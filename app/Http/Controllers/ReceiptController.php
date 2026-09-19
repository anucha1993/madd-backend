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
            'cash_receipt_buyer_suggestion' => [
                'name' => $firstShipment->destination['contact_name'] ?? $firstShipment->destination['company'] ?? '',
                'tax_id' => $firstShipment->destination['tax_id'] ?? null,
                'address' => $this->formatAddress($firstShipment->destination ?? []),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:CASH_RECEIPT,TAX_INVOICE'],
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
        if (abs($targetTotal - $linesTotal) > 0.01) {
            throw ValidationException::withMessages([
                'lines' => "ยอดรวมรายการ ({$linesTotal}) ไม่ตรงกับราคาขายรวมของ Shipment ที่เลือก ({$targetTotal})",
            ]);
        }

        $isTaxInvoice = $data['type'] === 'TAX_INVOICE';
        $vatRate = $isTaxInvoice ? (float) ($data['vat_rate'] ?? 7.00) : 0;
        // Shipment sell prices are already VAT-INCLUSIVE — line amounts are never marked up by
        // VAT on top, only EXTRACTED from the vat-marked lines for the Tax Invoice's legal
        // breakdown, so grand_total always equals exactly what was entered (== $linesTotal).
        $nonVatLinesTotal = $isTaxInvoice ? round(collect($data['lines'])->where('is_non_vat', true)->sum('amount'), 2) : 0;
        $vatInclusiveLinesTotal = $isTaxInvoice ? round(collect($data['lines'])->where('is_non_vat', false)->sum('amount'), 2) : $linesTotal;
        $subtotalVat = $isTaxInvoice ? round($vatInclusiveLinesTotal / (1 + $vatRate / 100), 2) : $vatInclusiveLinesTotal;
        $vatAmount = $isTaxInvoice ? round($vatInclusiveLinesTotal - $subtotalVat, 2) : 0;
        $subtotalNonVat = $nonVatLinesTotal;
        $grandTotal = round($subtotalNonVat + $subtotalVat + $vatAmount, 2);

        try {
            $receipt = DB::transaction(function () use ($data, $branchId, $subtotalNonVat, $subtotalVat, $vatRate, $vatAmount, $grandTotal, $request, $shipments) {
                $volNo = $this->documentNumberService->next($branchId, $data['type'], 'vol_no');
                $no = $this->documentNumberService->next($branchId, $data['type'], 'no');

                $receipt = Receipt::create([
                    'type' => $data['type'],
                    'branch_id' => $branchId,
                    'vol_no' => $volNo,
                    'no' => $no,
                    'issued_date' => now()->toDateString(),
                    'billing_customer_id' => $data['billing_customer_id'] ?? null,
                    'buyer_name' => $data['buyer_name'],
                    'buyer_tax_id' => $data['buyer_tax_id'] ?? null,
                    'buyer_address' => $data['buyer_address'] ?? null,
                    'buyer_is_head_office' => $data['buyer_is_head_office'] ?? true,
                    'buyer_branch_no' => $data['buyer_branch_no'] ?? null,
                    'subtotal_non_vat' => $subtotalNonVat,
                    'subtotal_vat' => $subtotalVat,
                    'vat_rate' => $vatRate,
                    'vat_amount' => $vatAmount,
                    'grand_total' => $grandTotal,
                    'grand_total_words' => $this->numberToWordsService->bahtText($grandTotal),
                    'payment_method' => $data['payment_method'] ?? null,
                    'payment_reference' => $data['payment_reference'] ?? null,
                    'status' => 'ISSUED',
                    'created_by' => $request->user()?->id,
                ]);

                foreach (array_values($data['lines']) as $index => $line) {
                    ReceiptLine::create([
                        'receipt_id' => $receipt->id,
                        'sort_order' => $index,
                        'description' => $line['description'],
                        'invoice_no' => $line['invoice_no'] ?? null,
                        'is_non_vat' => $line['is_non_vat'] ?? false,
                        'amount' => $line['amount'],
                    ]);
                }

                // Global lock — the unique index on shipment_id throws a QueryException if a
                // race condition already attached one of these shipments elsewhere, aborting
                // the whole transaction (nothing above is left half-created).
                $receipt->shipments()->attach($shipments->pluck('id'));

                return $receipt;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['error' => 'มี Shipment ที่เลือกถูกออกเอกสารไปแล้วโดยผู้ใช้อื่นพอดี กรุณาลองใหม่'], 409);
        }

        return response()->json($receipt->load('lines', 'branch', 'shipments'), 201);
    }

    /**
     * Corrects buyer details / line items / payment info on an already-issued document (e.g. a
     * typo in the buyer's name or address) without touching its Vol.No/No., type, or the locked
     * shipment set. Lines must still sum to the SAME grand_total (== the locked shipments' sell
     * price total, which can never change), so this can re-balance VAT/non-VAT split or reword
     * lines but never change how much was actually billed.
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

        $targetTotal = round((float) $receipt->grand_total, 2);
        $linesTotal = round(collect($data['lines'])->sum('amount'), 2);
        if (abs($targetTotal - $linesTotal) > 0.01) {
            throw ValidationException::withMessages([
                'lines' => "ยอดรวมรายการ ({$linesTotal}) ไม่ตรงกับยอดรวมเดิมของเอกสารนี้ ({$targetTotal})",
            ]);
        }

        $isTaxInvoice = $receipt->type === 'TAX_INVOICE';
        $vatRate = $isTaxInvoice ? (float) ($data['vat_rate'] ?? $receipt->vat_rate) : 0;
        $nonVatLinesTotal = $isTaxInvoice ? round(collect($data['lines'])->where('is_non_vat', true)->sum('amount'), 2) : 0;
        $vatInclusiveLinesTotal = $isTaxInvoice ? round(collect($data['lines'])->where('is_non_vat', false)->sum('amount'), 2) : $linesTotal;
        $subtotalVat = $isTaxInvoice ? round($vatInclusiveLinesTotal / (1 + $vatRate / 100), 2) : $vatInclusiveLinesTotal;
        $vatAmount = $isTaxInvoice ? round($vatInclusiveLinesTotal - $subtotalVat, 2) : 0;
        $subtotalNonVat = $nonVatLinesTotal;
        $grandTotal = round($subtotalNonVat + $subtotalVat + $vatAmount, 2);

        DB::transaction(function () use ($data, $receipt, $subtotalNonVat, $subtotalVat, $vatRate, $vatAmount, $grandTotal) {
            $receipt->update([
                'billing_customer_id' => $data['billing_customer_id'] ?? null,
                'buyer_name' => $data['buyer_name'],
                'buyer_tax_id' => $data['buyer_tax_id'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'buyer_is_head_office' => $data['buyer_is_head_office'] ?? true,
                'buyer_branch_no' => $data['buyer_branch_no'] ?? null,
                'subtotal_non_vat' => $subtotalNonVat,
                'subtotal_vat' => $subtotalVat,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'grand_total' => $grandTotal,
                'grand_total_words' => $this->numberToWordsService->bahtText($grandTotal),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
            ]);

            $receipt->lines()->delete();
            foreach (array_values($data['lines']) as $index => $line) {
                ReceiptLine::create([
                    'receipt_id' => $receipt->id,
                    'sort_order' => $index,
                    'description' => $line['description'],
                    'invoice_no' => $line['invoice_no'] ?? null,
                    'is_non_vat' => $line['is_non_vat'] ?? false,
                    'amount' => $line['amount'],
                ]);
            }
        });

        return response()->json($receipt->fresh()->load('lines', 'branch', 'shipments'));
    }

    /**
     * Permanently deletes a Receipt/Tax Invoice — only ever allowed when EVERY shipment it covers
     * was booked in Test mode (see Receipt::getIsTestAttribute()). Unlike void(), this actually
     * detaches the shipment<->receipt lock rows, so the shipments become billable again on a
     * fresh document — real/production documents can only ever be Voided, never deleted.
     */
    public function destroy(Receipt $receipt)
    {
        $receipt->loadMissing('shipments.agentAccount');
        if (! $receipt->is_test) {
            return response()->json(['error' => 'ลบได้เฉพาะเอกสารที่ออกจาก Shipment โหมด Test เท่านั้น กรุณาใช้ Void แทน'], 403);
        }

        DB::transaction(function () use ($receipt) {
            $receipt->lines()->delete();
            $receipt->shipments()->detach();
            $receipt->delete();
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

        // Voiding is a status flag only — the shipment<->receipt lock rows are NEVER removed,
        // so these shipments can never be re-billed on a new document (see module memory note).
        $receipt->update([
            'status' => 'VOIDED',
            'voided_at' => now(),
            'void_note' => $data['void_note'] ?? null,
        ]);

        return $receipt;
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
