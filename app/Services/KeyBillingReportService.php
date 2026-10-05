<?php

namespace App\Services;

use App\Models\ChargeCode;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * Builds KEY BILLING REPORT rows — the "SALES impacted by Carrier Invoice cost" reconciliation
 * report (see /memories/repo/carrier-invoice-ocr-module.md), DISTINCT from `/billing/carrier-invoices`
 * (which only imports the carrier's cost) and from ManifestReportService (which only builds the
 * Manifest document). One row per tracking number (same shipment-merge rule as
 * ManifestReportService::rowForTracking — multiple internal Shipment records sharing one real
 * tracking number are summed together, not listed twice).
 *
 * v1 scope (2026-10-02, pending the exact `MADD Report Proposal & Open Questions.xlsx` sheet 9
 * layout): shows SELL price (order_total) next to COST estimated at booking time
 * (Shipment.cost_amount) and COST actually billed by the carrier (sum of matched
 * CarrierInvoiceLine.effective_amount) side by side, with the resulting margin for each — lets
 * staff see where a booking's real margin differs from what was estimated once the real invoice
 * arrives. Full identity/per-charge-code columns from the roadmap spec can be layered on once the
 * exact Excel layout is confirmed.
 */
class KeyBillingReportService
{
    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return array<int, array<string, mixed>>
     */
    public function buildRows(Collection $shipments): array
    {
        $byTracking = $shipments->groupBy(fn (Shipment $s) => $s->tracking_number ?: 'shipment-'.$s->id);

        return $byTracking->map(fn (Collection $group) => $this->rowForTracking($group))->values()->all();
    }

    /** @param  Collection<int, Shipment>  $shipments */
    private function rowForTracking(Collection $shipments): array
    {
        $first = $shipments->first();

        $sellingTotal = round((float) $shipments->sum('order_total'), 2);
        $costEstimate = round((float) $shipments->sum('cost_amount'), 2);

        $matchedLines = $shipments->flatMap(fn (Shipment $s) => $s->carrierInvoiceLines);
        $costInvoice = $matchedLines->isEmpty() ? null : round((float) $matchedLines->sum(fn ($line) => (float) $line->effective_amount), 2);
        $invoiceNos = $matchedLines->map(fn ($line) => $line->invoice?->invoice_no)->filter()->unique()->values()->all();

        return [
            'tracking' => $first->tracking_number,
            'branch' => $first->branch?->name,
            'carrier' => $first->carrier,
            'account_number' => $first->agentAccount?->username_acc,
            'destination_country' => $first->destination['country'] ?? null,
            'customer_type' => $first->customer_type,
            'payment_method' => $first->payment_method,
            'created_at' => $first->created_at,
            'selling_total' => $sellingTotal,
            'cost_estimate' => $costEstimate,
            'cost_invoice' => $costInvoice,
            'cost_variance' => $costInvoice === null ? null : round($costInvoice - $costEstimate, 2),
            'margin_estimate' => round($sellingTotal - $costEstimate, 2),
            'margin_actual' => $costInvoice === null ? null : round($sellingTotal - $costInvoice, 2),
            'invoice_no' => $invoiceNos ? implode(', ', $invoiceNos) : null,
            'invoice_matched' => $costInvoice !== null,
        ];
    }

    /**
     * Builds rows matching the EXACT column spec of the reference template
     * "ข้อมูลดึงเป็นรายงาน key billing.xlsx" (read 2026-10-02), used only by the Excel export —
     * the simplified on-screen dashboard above (`buildRows`) is unaffected.
     *
     * Column -> data source mapping:
     * - COST section (cost_*): CarrierInvoiceLine.description keyword-matched into the template's
     *   8 named buckets (Freight/Fuel/FORM/OT/Adult Signature Required/Surge Fee-Com/Large
     *   Package Surcharge/Total) — `cost_total` is the sum of ALL matched lines (not just the 8
     *   buckets), matching the real invoice total even for charge types the template doesn't
     *   break out individually (e.g. Residential, Declared Value, Intl Processing Fee all still
     *   count toward cost_total but have no dedicated COST column in this template).
     * - SALE section (sell_* / ins_*): rate_quote.chargeBreakdown lines (keyword + ChargeCode
     *   category matched, BASE excluded — it's `sell_freight` already) PLUS addon_lines for
     *   Insurance/Form/OT. `ins_silver`/`ins_non_silver` and `inv_value_silver`/
     *   `inv_value_non_silver`: the system does NOT track a Silver vs Non-Silver insurance
     *   product tier yet (confirmed absent from Shipment/addon data, see
     *   /memories/repo/plan-2026-roadmap.md step 1) — everything is reported under the
     *   NON-SILVER column for now; revisit once that tier is actually captured at booking time.
     * - OTHER CHARGE (metal_box/other_charge): addon_lines "Metal Box"/"Other" categories — kept
     *   SEPARATE from `sell_other` (which only catches uncategorized chargeBreakdown lines) since
     *   these addon fees have no equivalent on the carrier's own invoice at all.
     * - `selling_grand_total`: Shipment.order_total directly (ground truth), not a re-sum of the
     *   columns above.
     * - Payment Option columns: one-hot by `payment_method` (DAY/CR/QR/TR/CARD/CHQ/PEND/OTHER,
     *   see ManifestOptionSeeder) — the shipment's `selling_grand_total` under whichever single
     *   column matches, 0 in every other column.
     * - PRODUCT / SERVICE TYPE: best-effort — `carrier` (UPS/DHL) and `service_label` respectively
     *   (no separate "product line" field exists on Shipment beyond these).
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<int, array<string, mixed>>
     */
    public function buildTemplateRows(Collection $shipments): array
    {
        $chargeCodesByProvider = ChargeCode::all()->groupBy('provider')->map(fn ($codes) => $codes->keyBy('code'));

        $byTracking = $shipments->groupBy(fn (Shipment $s) => $s->tracking_number ?: 'shipment-'.$s->id);

        return $byTracking->map(fn (Collection $group) => $this->templateRowForTracking($group, $chargeCodesByProvider))->values()->all();
    }

    /** @param  Collection<int, Shipment>  $shipments */
    private function templateRowForTracking(Collection $shipments, Collection $chargeCodesByProvider): array
    {
        $rows = $shipments->map(fn (Shipment $s) => $this->templateRowForShipment($s, $chargeCodesByProvider->get($s->carrier)))->values();
        if ($rows->count() === 1) {
            return $rows->first();
        }

        $merged = $rows->first();
        $numericKeys = array_diff(array_keys($merged), [
            'date', 'account', 'tracking', 'ref', 'zone', 'dest',
            'shipper', 'shipper_tax_id', 'shipper_address',
            'consignee', 'consignee_tax_id', 'consignee_address',
            'customer_type', 'product', 'service_type', 'remark',
        ]);
        foreach ($numericKeys as $key) {
            $merged[$key] = round((float) $rows->sum($key), 2);
        }
        $merged['pkgs'] = (int) $rows->sum('pkgs');
        $merged['ref'] = $rows->pluck('ref')->filter()->unique()->implode(', ') ?: null;
        $merged['remark'] = $rows->pluck('remark')->filter()->unique()->implode('; ') ?: null;

        return $merged;
    }

    private function templateRowForShipment(Shipment $shipment, ?Collection $chargeCodes): array
    {
        $packages = collect($shipment->packages ?? []);
        $actWeight = $packages->sum(fn ($p) => (float) ($p['weight'] ?? 0) * (int) ($p['quantity'] ?? 1));
        $dimWeight = $packages->sum(function ($p) {
            if (! empty($p['is_document'])) {
                return 0;
            }
            $l = (float) ($p['length'] ?? 0);
            $w = (float) ($p['width'] ?? 0);
            $h = (float) ($p['height'] ?? 0);

            return ($l * $w * $h / 5000) * (int) ($p['quantity'] ?? 1);
        });
        $pkgCount = $packages->sum(fn ($p) => (int) ($p['quantity'] ?? 1));
        $invValue = $packages->sum(fn ($p) => (float) ($p['declared_value'] ?? 0));

        $cost = $this->bucketCarrierInvoiceCost($shipment);
        $sale = $this->bucketSaleCharges($shipment, $chargeCodes);

        $receipt = $shipment->receipts->first();
        $ref = $receipt ? trim(($receipt->vol_no ?? '').'/'.($receipt->no ?? ''), '/') : null;
        $remark = null;
        if ($receipt && $receipt->variance_amount !== null) {
            $variance = round((float) $receipt->variance_amount, 2);
            if ($variance !== 0.0) {
                $remark = 'ส่วนต่าง '.($variance > 0 ? '+' : '').number_format($variance, 2);
            }
        }

        $grandTotal = round((float) $shipment->order_total, 2);
        $paymentCode = strtoupper((string) ($shipment->payment_method ?? ''));
        $payment = [
            'pay_daily' => 0.0, 'pay_cr' => 0.0, 'pay_qr' => 0.0, 'pay_transfer' => 0.0,
            'pay_card' => 0.0, 'pay_chq' => 0.0, 'pay_pending' => 0.0, 'pay_other' => 0.0,
        ];
        $paymentKey = match ($paymentCode) {
            'DAY' => 'pay_daily', 'CR' => 'pay_cr', 'QR' => 'pay_qr', 'TR' => 'pay_transfer',
            'CARD' => 'pay_card', 'CHQ' => 'pay_chq', 'PEND' => 'pay_pending',
            default => $paymentCode !== '' ? 'pay_other' : null,
        };
        if ($paymentKey) {
            $payment[$paymentKey] = $grandTotal;
        }

        return array_merge([
            'date' => $shipment->created_at,
            'account' => $shipment->agentAccount?->username_acc,
            'tracking' => $shipment->tracking_number,
            'ref' => $ref,
            'zone' => $shipment->rate_quote['zone'] ?? null,
            'dest' => $shipment->destination['country'] ?? null,
            'shipper' => $shipment->origin['company'] ?? $shipment->origin['contact_name'] ?? null,
            'shipper_tax_id' => $shipment->origin['tax_id'] ?? null,
            'shipper_address' => $this->formatAddress($shipment->origin ?? []),
            'consignee' => $shipment->destination['company'] ?? $shipment->destination['contact_name'] ?? null,
            'consignee_tax_id' => $shipment->destination['tax_id'] ?? null,
            'consignee_address' => $this->formatAddress($shipment->destination ?? []),
            'customer_type' => $shipment->customer_type,
            'product' => $shipment->carrier,
            'service_type' => $shipment->service_label,
            'pkgs' => $pkgCount,
            'act_kg' => round($actWeight, 2),
            'dim_kg' => round($dimWeight, 2),
        ], $cost, $sale, [
            'metal_box' => $sale['_metal_box'],
            'other_charge' => $sale['_other_charge'],
            'selling_grand_total' => $grandTotal,
        ], $payment, [
            'remark' => $remark,
            'inv_value_silver' => 0.0,
            'inv_value_non_silver' => round($invValue, 2),
        ]);
    }

    private function formatAddress(array $addr): ?string
    {
        $parts = array_filter([$addr['address'] ?? null, $addr['address2'] ?? null, $addr['address3'] ?? null, $addr['city'] ?? null, $addr['state'] ?? null, $addr['postcode'] ?? null]);

        return $parts ? implode(', ', $parts) : null;
    }

    /** @return array<string, float> cost_freight/cost_fuel/cost_form/cost_ot/cost_adult_sig/cost_surge_com/cost_large_pkg/cost_total */
    private function bucketCarrierInvoiceCost(Shipment $shipment): array
    {
        $buckets = [
            'cost_freight' => 0.0, 'cost_fuel' => 0.0, 'cost_form' => 0.0, 'cost_ot' => 0.0,
            'cost_adult_sig' => 0.0, 'cost_surge_com' => 0.0, 'cost_large_pkg' => 0.0,
        ];
        $total = 0.0;

        foreach ($shipment->carrierInvoiceLines as $line) {
            $amount = (float) $line->effective_amount;
            $total += $amount;
            $desc = mb_strtolower((string) $line->description);

            if (str_contains($desc, 'adult signature')) {
                $buckets['cost_adult_sig'] += $amount;
            } elseif (str_contains($desc, 'large package')) {
                $buckets['cost_large_pkg'] += $amount;
            } elseif (str_contains($desc, 'surge fee') && str_contains($desc, 'com')) {
                $buckets['cost_surge_com'] += $amount;
            } elseif (str_contains($desc, 'fuel')) {
                $buckets['cost_fuel'] += $amount;
            } elseif (trim($desc) === 'freight') {
                $buckets['cost_freight'] += $amount;
            } elseif (str_contains($desc, 'form')) {
                $buckets['cost_form'] += $amount;
            } elseif (trim($desc) === 'ot' || str_contains($desc, 'ot fee')) {
                $buckets['cost_ot'] += $amount;
            }
            // Any other description (Residential, Declared Value, Surge Fee - Resi, Delivery
            // Area Surcharge, International Processing Fee, ...) only counts toward cost_total —
            // this template has no dedicated COST column for them.
        }

        $buckets['cost_total'] = round($total, 2);

        return array_map(fn ($v) => round($v, 2), $buckets);
    }

    /** @return array<string, mixed> sell_* / ins_* / _metal_box / _other_charge (the latter two merged into their own top-level keys by the caller) */
    private function bucketSaleCharges(Shipment $shipment, ?Collection $chargeCodes): array
    {
        $buckets = [
            'sell_freight' => round((float) $shipment->freight_amount, 2),
            'ins_silver' => 0.0, 'ins_non_silver' => 0.0,
            'sell_form' => 0.0, 'sell_ot' => 0.0, 'sell_residential' => 0.0, 'sell_add_handling' => 0.0,
            'sell_adult_sig' => 0.0, 'sell_surge_com' => 0.0, 'sell_surcharge' => 0.0,
            'sell_intl_processing' => 0.0, 'sell_large_pkg' => 0.0, 'sell_declared_value' => 0.0, 'sell_other' => 0.0,
        ];
        $metalBox = 0.0;
        $otherCharge = 0.0;

        foreach ($shipment->rate_quote['chargeBreakdown'] ?? [] as $line) {
            $code = (string) ($line['code'] ?? '');
            if ($code === 'BASE') {
                continue; // already counted as sell_freight
            }
            $amount = (float) ($line['amount'] ?? 0);
            $category = $chargeCodes?->get($code)?->category;
            $desc = mb_strtolower((string) ($line['description'] ?? ''));

            if ($category === 'INSURANCE') {
                $buckets['sell_declared_value'] += $amount;
            } elseif (str_contains($desc, 'adult signature')) {
                $buckets['sell_adult_sig'] += $amount;
            } elseif (str_contains($desc, 'large package')) {
                $buckets['sell_large_pkg'] += $amount;
            } elseif (str_contains($desc, 'surge fee')) {
                $buckets['sell_surge_com'] += $amount;
            } elseif (str_contains($desc, 'international processing')) {
                $buckets['sell_intl_processing'] += $amount;
            } elseif (str_contains($desc, 'residential')) {
                $buckets['sell_residential'] += $amount;
            } elseif (str_contains($desc, 'additional handling')) {
                $buckets['sell_add_handling'] += $amount;
            } elseif ($category === 'SURCHARGE') {
                $buckets['sell_surcharge'] += $amount;
            } else {
                $buckets['sell_other'] += $amount;
            }
        }

        foreach ($shipment->addon_lines ?? [] as $addon) {
            $amount = (float) ($addon['quantity'] ?? 1) * (float) ($addon['unit_price'] ?? 0);
            $category = $addon['category'] ?? null;
            $name = mb_strtolower((string) ($addon['name'] ?? ''));

            if ($category === 'Insurance') {
                $buckets['ins_non_silver'] += $amount;
            } elseif ($category === 'Metal Box') {
                $metalBox += $amount;
            } elseif ($category === 'Form / OT Fee') {
                if (str_contains($name, 'form')) {
                    $buckets['sell_form'] += $amount;
                } else {
                    $buckets['sell_ot'] += $amount;
                }
            } else {
                $otherCharge += $amount;
            }
        }

        $buckets = array_map(fn ($v) => round($v, 2), $buckets);
        $buckets['sell_total'] = round(array_sum($buckets), 2);
        $buckets['_metal_box'] = round($metalBox, 2);
        $buckets['_other_charge'] = round($otherCharge, 2);

        return $buckets;
    }
}
