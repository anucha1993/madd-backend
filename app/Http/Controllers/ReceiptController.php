<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Shipment;
use App\Services\DocumentNumberService;
use App\Services\NumberToWordsService;
use App\Services\ReceiptPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function __construct(
        private DocumentNumberService $documentNumberService,
        private NumberToWordsService $numberToWordsService,
        private ReceiptPdfService $receiptPdfService,
    ) {
    }

    public function index(Request $request)
    {
        // Eager-load shipments.agentAccount up front so the `is_test` accessor (used to decide
        // whether the row's Delete action is shown) never triggers a per-row N+1 query.
        $query = Receipt::with('branch', 'shipments.agentAccount')->latest();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('vol_no', 'like', "%{$search}%")
                    ->orWhere('no', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%");
            });
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('issued_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('issued_date', '<=', $dateTo);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Receipt $receipt)
    {
        return $receipt->load('branch', 'billingCustomer', 'lines', 'shipments.agentAccount.agent', 'createdBy');
    }

    /**
     * Given a set of NOT-YET-BILLED shipments, returns the shared branch, the target total they
     * must sum to, and a suggested starting set of line items (grouped by charge description,
     * summed across every selected shipment) — fully editable afterward on the frontend.
     */
    public function previewLines(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
        ]);

        $shipments = Shipment::whereIn('id', $data['shipment_ids'])->get();
        $this->assertShipmentsBillable($shipments);

        $branchId = $shipments->first()->branch_id;
        $targetTotal = round((float) $shipments->sum('order_total'), 2);

        $totalsByDescription = [];
        foreach ($shipments as $shipment) {
            // Tag every suggested line with the shipment's carrier — keeps UPS and DHL charges
            // on SEPARATE lines (never folded together) so a mixed-carrier document still shows
            // exactly which carrier each amount belongs to.
            $carrierTag = " ({$shipment->carrier})";
            $chargeBreakdown = $shipment->rate_quote['chargeBreakdown'] ?? null;
            if (empty($chargeBreakdown)) {
                // No detailed breakdown at all — order_total already covers freight + add-ons
                // together, so use it as ONE lump line (don't also add addon_lines below, that
                // would double-count them).
                $description = 'FREIGHT SERVICE'.$carrierTag;
                $totalsByDescription[$description] = ($totalsByDescription[$description] ?? 0) + (float) $shipment->order_total;

                continue;
            }
            foreach ($chargeBreakdown as $line) {
                $description = mb_strtoupper(trim((string) ($line['description'] ?? 'CHARGE'))).$carrierTag;
                $totalsByDescription[$description] = ($totalsByDescription[$description] ?? 0) + (float) ($line['amount'] ?? 0);
            }

            // Add-ons (Form/OT/Metal Box/Insurance/etc, sold on top of the carrier's own freight
            // charges — see BookShipmentAddonLine) are a SEPARATE line array from chargeBreakdown
            // entirely and were previously never folded into the suggested lines at all.
            foreach ($shipment->addon_lines ?? [] as $addon) {
                $description = mb_strtoupper(trim((string) ($addon['name'] ?? 'ADD-ON'))).$carrierTag;
                $amount = (float) ($addon['quantity'] ?? 1) * (float) ($addon['unit_price'] ?? 0);
                $totalsByDescription[$description] = ($totalsByDescription[$description] ?? 0) + $amount;
            }
        }

        $lines = array_map(
            fn ($description, $amount) => ['description' => $description, 'is_non_vat' => false, 'amount' => round($amount, 2)],
            array_keys($totalsByDescription),
            array_values($totalsByDescription),
        );

        // The shipment's chargeBreakdown lines are PRE-VAT (VAT/other add-ons are applied later
        // to reach the final order_total, see plan-2026-roadmap.md's sell-price formula) — but
        // order_total ITSELF is already VAT-inclusive and must never be added/subtracted from.
        // Scale every suggested line proportionally so they sum EXACTLY to target_total, with the
        // last line absorbing any rounding residual — staff never has to manually reconcile a gap.
        $rawSum = round(array_sum(array_column($lines, 'amount')), 2);
        if ($rawSum > 0 && abs($rawSum - $targetTotal) > 0.01) {
            $scale = $targetTotal / $rawSum;
            $running = 0.0;
            foreach ($lines as $index => &$line) {
                if ($index === count($lines) - 1) {
                    $line['amount'] = round($targetTotal - $running, 2);
                } else {
                    $line['amount'] = round($line['amount'] * $scale, 2);
                    $running += $line['amount'];
                }
            }
            unset($line);
        }

        $firstShipment = $shipments->first();

        return response()->json([
            'branch_id' => $branchId,
            'target_total' => $targetTotal,
            'lines' => $lines,
            // Suggested starting buyer details (from the first selected shipment's Ship-To) —
            // used to prefill the shared Buyer section before staff pick a real Tax Invoice
            // billing_customer or retype it, since Cash Receipt + Tax Invoice are now always
            // issued together with ONE shared buyer (2026-09-23).
            'buyer_suggestion' => [
                'name' => $firstShipment->destination['contact_name'] ?? $firstShipment->destination['company'] ?? '',
                'tax_id' => $firstShipment->destination['tax_id'] ?? null,
                'address' => $this->formatAddress($firstShipment->destination ?? []),
            ],
        ]);
    }

    /**
     * Issues a Cash Receipt + Tax Invoice TOGETHER, always as one inseparable pair (2026-09-23:
     * staff no longer choose either/or) — same buyer, same shipments, same line items, but each
     * gets its OWN independent Vol.No/No. from its own document_number_sequences counter, and
     * each is downloaded/printed as its own separate PDF (see ReceiptPdfService, pdf() below).
     * Both rows share one `receipt_group_id` so update()/void()/destroy() can cascade the pair.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
            'billing_customer_id' => ['nullable', 'integer', 'exists:billing_customers,id'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_tax_id' => ['nullable', 'string', 'max:20'],
            'buyer_address' => ['nullable', 'string', 'max:1000'],
            'buyer_is_head_office' => ['boolean'],
            'buyer_branch_no' => ['nullable', 'string', 'max:50'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.invoice_no' => ['nullable', 'string', 'max:100'],
            'lines.*.is_non_vat' => ['boolean'],
            'lines.*.amount' => ['required', 'numeric'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $shipments = Shipment::whereIn('id', $data['shipment_ids'])->get();
        $this->assertShipmentsBillable($shipments);
        $branchId = $shipments->first()->branch_id;

        $targetTotal = round((float) $shipments->sum('order_total'), 2);
        $linesTotal = round(collect($data['lines'])->sum('amount'), 2);
        // Line-items total no longer needs to match the shipments' sell price exactly — some
        // customers are billed MORE than the shipment cost. The difference is recorded via
        // shipment_total_snapshot (see Receipt::getVarianceAmountAttribute()) instead of blocked.

        $totals = $this->computeTotals($data['lines'], $linesTotal, (float) ($data['vat_rate'] ?? 7.00));

        try {
            [$cashReceipt, $taxInvoice] = DB::transaction(function () use ($data, $branchId, $totals, $targetTotal, $request, $shipments) {
                $groupId = (string) Str::uuid();

                $commonFields = [
                    'receipt_group_id' => $groupId,
                    'branch_id' => $branchId,
                    'issued_date' => now()->toDateString(),
                    'shipment_total_snapshot' => $targetTotal,
                    'billing_customer_id' => $data['billing_customer_id'] ?? null,
                    'buyer_name' => $data['buyer_name'],
                    'buyer_tax_id' => $data['buyer_tax_id'] ?? null,
                    'buyer_address' => $data['buyer_address'] ?? null,
                    'buyer_is_head_office' => $data['buyer_is_head_office'] ?? true,
                    'buyer_branch_no' => $data['buyer_branch_no'] ?? null,
                    'payment_method' => $data['payment_method'] ?? null,
                    'payment_reference' => $data['payment_reference'] ?? null,
                    'status' => 'ISSUED',
                    'created_by' => $request->user()?->id,
                ];

                $cashReceipt = Receipt::create(array_merge($commonFields, [
                    'type' => 'CASH_RECEIPT',
                    'vol_no' => $this->documentNumberService->next($branchId, 'CASH_RECEIPT', 'vol_no'),
                    'no' => $this->documentNumberService->next($branchId, 'CASH_RECEIPT', 'no'),
                ], $totals['cash_receipt']));

                $taxInvoice = Receipt::create(array_merge($commonFields, [
                    'type' => 'TAX_INVOICE',
                    'vol_no' => $this->documentNumberService->next($branchId, 'TAX_INVOICE', 'vol_no'),
                    'no' => $this->documentNumberService->next($branchId, 'TAX_INVOICE', 'no'),
                ], $totals['tax_invoice']));

                foreach ([$cashReceipt, $taxInvoice] as $receipt) {
                    $this->replaceLines($receipt, $data['lines']);
                }

                // Per-type global lock — the unique index on (shipment_id, type) throws a
                // QueryException if a race condition already attached one of these shipments to
                // either document type elsewhere, aborting the whole transaction.
                $shipmentIds = $shipments->pluck('id');
                $cashReceipt->shipments()->attach($shipmentIds->mapWithKeys(fn ($id) => [$id => ['type' => 'CASH_RECEIPT']])->all());
                $taxInvoice->shipments()->attach($shipmentIds->mapWithKeys(fn ($id) => [$id => ['type' => 'TAX_INVOICE']])->all());

                return [$cashReceipt, $taxInvoice];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['error' => 'มี Shipment ที่เลือกถูกออกเอกสารไปแล้วโดยผู้ใช้อื่นพอดี กรุณาลองใหม่'], 409);
        }

        return response()->json([
            'cash_receipt' => $cashReceipt->load('lines', 'branch', 'shipments'),
            'tax_invoice' => $taxInvoice->load('lines', 'branch', 'shipments'),
        ], 201);
    }

    /**
     * Shared line-items -> financial totals math for BOTH paired documents. Shipment sell prices
     * are already VAT-INCLUSIVE — VAT is only ever EXTRACTED from the vat-marked lines for the
     * Tax Invoice's legal breakdown, never added on top, so both documents' grand_total always
     * equals exactly the same $linesTotal that was entered.
     *
     * @return array{cash_receipt: array<string, mixed>, tax_invoice: array<string, mixed>}
     */
    private function computeTotals(array $lines, float $linesTotal, float $vatRate): array
    {
        $nonVatLinesTotal = round(collect($lines)->where('is_non_vat', true)->sum('amount'), 2);
        $vatInclusiveLinesTotal = round(collect($lines)->where('is_non_vat', false)->sum('amount'), 2);
        $subtotalVat = round($vatInclusiveLinesTotal / (1 + $vatRate / 100), 2);
        $vatAmount = round($vatInclusiveLinesTotal - $subtotalVat, 2);
        $grandTotal = round($nonVatLinesTotal + $subtotalVat + $vatAmount, 2);

        return [
            // Cash Receipt page never itemizes VAT — always one combined line (see
            // receipt-page.blade.php's isCombinedLine).
            'cash_receipt' => [
                'subtotal_non_vat' => 0,
                'subtotal_vat' => $linesTotal,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'grand_total' => $linesTotal,
                'grand_total_words' => $this->numberToWordsService->bahtText($linesTotal),
            ],
            'tax_invoice' => [
                'subtotal_non_vat' => $nonVatLinesTotal,
                'subtotal_vat' => $subtotalVat,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'grand_total' => $grandTotal,
                'grand_total_words' => $this->numberToWordsService->bahtText($grandTotal),
            ],
        ];
    }

    /**
     * @param  array<int, array{description: string, invoice_no?: ?string, is_non_vat?: bool, amount: float}>  $lines
     */
    private function replaceLines(Receipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();
        foreach (array_values($lines) as $index => $line) {
            ReceiptLine::create([
                'receipt_id' => $receipt->id,
                'sort_order' => $index,
                'description' => $line['description'],
                'invoice_no' => $line['invoice_no'] ?? null,
                'is_non_vat' => $line['is_non_vat'] ?? false,
                'amount' => $line['amount'],
            ]);
        }
    }

    /**
     * Corrects buyer details / line items / payment info on an already-issued document (e.g. a
     * typo in the buyer's name or address) without touching its Vol.No/No., type, or the locked
     * shipment set. Lines must still sum to the SAME grand_total (== the locked shipments' sell
     * price total, which can never change), so this can re-balance VAT/non-VAT split or reword
     * lines but never change how much was actually billed.
     */
    /**
     * Corrects buyer details / line items / payment info on an already-issued document (e.g. a
     * typo in the buyer's name or address) without touching its Vol.No/No., type, or the locked
     * shipment set. Cascades to its paired Cash Receipt/Tax Invoice (same receipt_group_id, see
     * store()) since 2026-09-23 — they share one buyer + one set of line items, only each
     * document's own subtotal_non_vat/vat/grand_total is recomputed per its own type's rules.
     * Lines must still sum to the SAME grand_total (== the locked shipments' sell price total,
     * which can never change).
     */
    public function update(Request $request, Receipt $receipt)
    {
        if ($receipt->status === 'VOIDED') {
            return response()->json(['error' => 'เอกสารนี้ถูกยกเลิกไปแล้ว ไม่สามารถแก้ไขได้'], 422);
        }

        $data = $request->validate([
            'billing_customer_id' => ['nullable', 'integer', 'exists:billing_customers,id'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_tax_id' => ['nullable', 'string', 'max:20'],
            'buyer_address' => ['nullable', 'string', 'max:1000'],
            'buyer_is_head_office' => ['boolean'],
            'buyer_branch_no' => ['nullable', 'string', 'max:50'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.invoice_no' => ['nullable', 'string', 'max:100'],
            'lines.*.is_non_vat' => ['boolean'],
            'lines.*.amount' => ['required', 'numeric'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        // Line-items total no longer has to match the document's existing grand_total exactly —
        // same "billed more/less than shipment cost" allowance as store() (see
        // shipment_total_snapshot/variance_amount on the Receipt model). Edited lines simply
        // recompute grand_total from scratch; shipment_total_snapshot stays untouched so the
        // variance is still visible afterward.
        $linesTotal = round(collect($data['lines'])->sum('amount'), 2);
        $totals = $this->computeTotals($data['lines'], $linesTotal, (float) ($data['vat_rate'] ?? 7.00));
        $pair = $receipt->pairedReceipt();

        DB::transaction(function () use ($data, $receipt, $pair, $totals) {
            foreach (array_filter([$receipt, $pair]) as $r) {
                $ownTotals = $r->type === 'TAX_INVOICE' ? $totals['tax_invoice'] : $totals['cash_receipt'];
                $r->update(array_merge([
                    'billing_customer_id' => $data['billing_customer_id'] ?? null,
                    'buyer_name' => $data['buyer_name'],
                    'buyer_tax_id' => $data['buyer_tax_id'] ?? null,
                    'buyer_address' => $data['buyer_address'] ?? null,
                    'buyer_is_head_office' => $data['buyer_is_head_office'] ?? true,
                    'buyer_branch_no' => $data['buyer_branch_no'] ?? null,
                    'payment_method' => $data['payment_method'] ?? null,
                    'payment_reference' => $data['payment_reference'] ?? null,
                ], $ownTotals));

                $this->replaceLines($r, $data['lines']);
            }
        });

        $receipt = $receipt->fresh()->load('lines', 'branch', 'shipments');
        $pair = $pair ? $pair->fresh()->load('lines', 'branch', 'shipments') : null;

        return response()->json([
            'cash_receipt' => $receipt->type === 'CASH_RECEIPT' ? $receipt : $pair,
            'tax_invoice' => $receipt->type === 'TAX_INVOICE' ? $receipt : $pair,
        ]);
    }

    /**
     * Permanently deletes a Receipt/Tax Invoice pair — only ever allowed when EVERY shipment
     * covered by BOTH paired documents was booked in Test mode (see Receipt::getIsTestAttribute()).
     * Unlike void(), this actually detaches the shipment<->receipt lock rows, so the shipments
     * become billable again on a fresh document — real/production documents can only ever be
     * Voided, never deleted.
     */
    public function destroy(Receipt $receipt)
    {
        $receipt->loadMissing('shipments.agentAccount');
        $pair = $receipt->pairedReceipt();
        $pair?->loadMissing('shipments.agentAccount');

        if (! $receipt->is_test || ($pair && ! $pair->is_test)) {
            return response()->json(['error' => 'ลบได้เฉพาะเอกสารที่ออกจาก Shipment โหมด Test เท่านั้น กรุณาใช้ Void แทน'], 403);
        }

        DB::transaction(function () use ($receipt, $pair) {
            foreach (array_filter([$receipt, $pair]) as $r) {
                $r->lines()->delete();
                $r->shipments()->detach();
                $r->delete();
            }
        });

        return response()->noContent();
    }

    public function void(Request $request, Receipt $receipt)
    {
        if ($receipt->status === 'VOIDED') {
            return response()->json(['error' => 'เอกสารนี้ถูกยกเลิกไปแล้ว'], 422);
        }

        $data = $request->validate([
            'void_note' => ['nullable', 'string', 'max:500'],
        ]);

        $pair = $receipt->pairedReceipt();

        // Voiding is a status flag only — the shipment<->receipt lock rows are NEVER removed, so
        // these shipments can never be re-billed on a new document. Cascades to the paired
        // document (same receipt_group_id) so a Cash Receipt + Tax Invoice issued together are
        // always voided together (2026-09-23).
        $now = now();
        foreach (array_filter([$receipt, $pair]) as $r) {
            if ($r->status !== 'VOIDED') {
                $r->update([
                    'status' => 'VOIDED',
                    'voided_at' => $now,
                    'void_note' => $data['void_note'] ?? null,
                ]);
            }
        }

        $receipt = $receipt->fresh();
        $pair = $pair?->fresh();

        return response()->json([
            'cash_receipt' => $receipt->type === 'CASH_RECEIPT' ? $receipt : $pair,
            'tax_invoice' => $receipt->type === 'TAX_INVOICE' ? $receipt : $pair,
        ]);
    }

    public function pdf(Receipt $receipt)
    {
        $pdf = $this->receiptPdfService->render($receipt);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$receipt->type.'-'.$receipt->no.'.pdf"',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Shipment>  $shipments
     */
    private function assertShipmentsBillable($shipments): void
    {
        if ($shipments->count() === 0) {
            throw ValidationException::withMessages(['shipment_ids' => 'ไม่พบ Shipment ที่เลือก']);
        }

        $alreadyBilled = $shipments->filter(fn ($s) => $s->receipts()->exists());
        if ($alreadyBilled->isNotEmpty()) {
            $numbers = $alreadyBilled->pluck('tracking_number')->filter()->implode(', ');
            throw ValidationException::withMessages([
                'shipment_ids' => "Shipment ต่อไปนี้ถูกออกใบเสร็จ/ใบกำกับภาษีไปแล้ว: {$numbers}",
            ]);
        }

        $branchIds = $shipments->pluck('branch_id')->unique();
        if ($branchIds->count() > 1 || $branchIds->first() === null) {
            throw ValidationException::withMessages([
                'shipment_ids' => 'Shipment ที่เลือกต้องมาจากสาขาเดียวกันเท่านั้น (และต้องมีสาขาระบุไว้)',
            ]);
        }
    }

    private function formatAddress(array $address): string
    {
        return collect([
            $address['address'] ?? null,
            $address['address2'] ?? null,
            $address['address3'] ?? null,
            $address['city'] ?? null,
            $address['postcode'] ?? null,
            $address['country'] ?? null,
        ])->filter()->implode(', ');
    }
}
