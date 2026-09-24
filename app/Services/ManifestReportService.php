<?php

namespace App\Services;

use App\Models\ChargeCode;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds MANIFEST report rows (see /memories/repo/plan-2026-roadmap.md sheet 7+8 "แบบฟอร์ม
 * manifest") from booked Shipments — one row per shipment, grouped into one header block per
 * (branch, agent account) pair so a multi-branch/multi-account export still reads like several
 * separate physical manifest sheets stacked together.
 *
 * Column -> data source mapping (confirmed with user 2026-09-21):
 * - Sur / Accs: rate_quote.chargeBreakdown lines, bucketed by charge_codes.category
 *   (FUEL/SURCHARGE -> Sur, HANDLING/SERVICE -> Accs).
 * - Ins. / Ins-Co: chargeBreakdown lines with category=INSURANCE, PLUS addon_lines whose
 *   snapshotted category is "Insurance" (3rd-party ICDV/UPSC/DHL insurance sold as an add-on,
 *   never part of chargeBreakdown at all).
 * - Metal / Form / Other: addon_lines bucketed by their snapshotted category ("Metal Box",
 *   "Form / OT Fee", "Other" respectively — see AddonCategorySeeder). Any chargeBreakdown line
 *   whose charge code category isn't one of the above also falls into Other.
 * - Ref: the shipment's issued Receipt/Tax Invoice "เล่มที่/เลขที่" (vol_no/no), since only
 *   shipments with an ISSUED receipt are ever included in this report (see
 *   ManifestReportController::queryShipments).
 * - Remark: the receipt's variance_amount (grand_total vs. shipment_total_snapshot, see
 *   Receipt::getVarianceAmountAttribute) formatted as "ส่วนต่าง ±X.XX" — blank when the buyer
 *   was billed exactly the shipment's sell price (the normal case) or for legacy receipts with
 *   no snapshot at all.
 * - Weight Dim: standard air-freight volumetric formula (L x W x H cm / 5000) per package,
 *   since no dedicated dimensional-weight field is stored separately from the carrier's own
 *   billed/chargeable weight.
 */
class ManifestReportService
{
    private const SUR_CATEGORIES = ['FUEL', 'SURCHARGE'];
    private const ACCS_CATEGORIES = ['HANDLING', 'SERVICE'];

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return array<int, array{header: array<string, mixed>, rows: array<int, array<string, mixed>>}>
     */
    public function buildGroups(Collection $shipments, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $chargeCodesByProvider = ChargeCode::all()->groupBy('provider')->map(fn ($codes) => $codes->keyBy('code'));

        $groups = $shipments->groupBy(fn (Shipment $s) => ($s->branch_id ?? 0).':'.($s->agent_account_id ?? 0));

        $result = [];
        foreach ($groups as $group) {
            $first = $group->first();
            // Multiple Shipment bookings can share the same real-world tracking number (e.g. a
            // package re-billed/split across several internal records) — the Manifest must only
            // ever show ONE row per tracking number, not one per Shipment (confirmed with user
            // 2026-09-24). Shipments with no tracking number at all never merge with each other.
            $byTracking = $group->groupBy(fn (Shipment $s) => $s->tracking_number ?: 'shipment-'.$s->id);

            $result[] = [
                'header' => [
                    'date' => $this->formatDateRange($from, $to, $group),
                    'account_number' => $first->agentAccount?->username_acc ?? '-',
                    'branch_name' => $first->branch?->name ?? '-',
                    'branch_code' => $first->branch?->code ?? '-',
                    'carrier' => $first->carrier,
                ],
                'rows' => $byTracking->map(fn ($shipments) => $this->rowForTracking($shipments, $chargeCodesByProvider))->values()->all(),
            ];
        }

        return $result;
    }

    /** Thai (Buddhist year) DD/MM/YYYY, e.g. "01/09/2569-30/09/2569" — falls back to the group's own shipment dates when no explicit filter range was given. */
    private function formatDateRange(?Carbon $from, ?Carbon $to, Collection $group): string
    {
        $from ??= $group->min('created_at');
        $to ??= $group->max('created_at');

        $thai = fn (Carbon $d) => $d->format('d/m/').($d->year + 543);

        return $from && $to ? $thai(Carbon::parse($from)).'-'.$thai(Carbon::parse($to)) : '-';
    }

    /**
     * Merges every Shipment sharing one tracking number into a single Manifest row: numeric
     * charge/weight/package columns are summed across all of them, while descriptive columns
     * (Zone/Pay/Dest/Type/Shipper/Consignee/Ins-Co/Tracking itself) are taken from the first
     * (earliest-created) shipment — it doesn't matter which one is "the reference" since all
     * their data is combined either way (confirmed with user 2026-09-24).
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    private function rowForTracking(Collection $shipments, Collection $chargeCodesByProvider): array
    {
        $rows = $shipments->map(fn (Shipment $s) => $this->rowForShipment($s, $chargeCodesByProvider->get($s->carrier)))->values();

        if ($rows->count() === 1) {
            return $rows->first();
        }

        $merged = $rows->first();
        foreach (['weight_act', 'weight_dim', 'freight', 'sur', 'accs', 'ins', 'metal', 'form', 'other', 'total_charge', 'inv_value'] as $key) {
            $merged[$key] = round((float) $rows->sum($key), 2);
        }
        $merged['pkg'] = (int) $rows->sum('pkg');
        // Almost always identical across the group (one tracking = one receipt in practice) —
        // joined instead of just picked-first in the rare case they do differ.
        $merged['ref'] = $rows->pluck('ref')->filter()->unique()->implode(', ') ?: null;
        $merged['remark'] = $rows->pluck('remark')->filter()->unique()->implode('; ') ?: null;

        return $merged;
    }

    private function rowForShipment(Shipment $shipment, ?Collection $chargeCodes): array
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

        $sur = 0.0;
        $accs = 0.0;
        $insFromApi = 0.0;
        $otherFromApi = 0.0;
        foreach ($shipment->rate_quote['chargeBreakdown'] ?? [] as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            $category = $chargeCodes?->get((string) ($line['code'] ?? ''))?->category;
            if (in_array($category, self::SUR_CATEGORIES, true)) {
                $sur += $amount;
            } elseif (in_array($category, self::ACCS_CATEGORIES, true)) {
                $accs += $amount;
            } elseif ($category === 'INSURANCE') {
                $insFromApi += $amount;
            } elseif ($category === 'BASE') {
                // Base freight is its own separate column — never double-counted here.
                continue;
            } else {
                $otherFromApi += $amount;
            }
        }

        $insFromAddon = 0.0;
        $insCo = null;
        $metal = 0.0;
        $form = 0.0;
        $otherFromAddon = 0.0;
        foreach ($shipment->addon_lines ?? [] as $addon) {
            $amount = (float) ($addon['quantity'] ?? 1) * (float) ($addon['unit_price'] ?? 0);
            $category = $addon['category'] ?? null;
            $name = (string) ($addon['name'] ?? '');
            if ($category === 'Insurance') {
                $insFromAddon += $amount;
                $insCo ??= $this->shortInsuranceCode($name);
            } elseif ($category === 'Metal Box') {
                $metal += $amount;
            } elseif ($category === 'Form / OT Fee') {
                $form += $amount;
            } else {
                $otherFromAddon += $amount;
            }
        }

        // Only ISSUED receipts count here — queryShipments() already restricts the whole report
        // to shipments with at least one, so this is always non-null in practice (see
        // ManifestReportController::queryShipments's whereHas + eager-load constraint).
        $receipt = $shipment->receipts->first();
        $ref = $receipt ? trim(($receipt->vol_no ?? '').'/'.($receipt->no ?? ''), '/') : null;

        // Remark only fires when the buyer was actually billed a different amount than the
        // shipment's own sell price at issue time (see Receipt::getVarianceAmountAttribute) —
        // null/0 variance (the normal case) leaves Remark blank.
        $remark = null;
        if ($receipt && $receipt->variance_amount !== null) {
            $variance = round((float) $receipt->variance_amount, 2);
            if ($variance !== 0.0) {
                $remark = 'ส่วนต่าง '.($variance > 0 ? '+' : '').number_format($variance, 2);
            }
        }

        return [
            'tracking' => $shipment->tracking_number,
            'ref' => $ref,
            'zone' => $shipment->rate_quote['zone'] ?? null,
            'weight_act' => round($actWeight, 2),
            'weight_dim' => round($dimWeight, 2),
            'pay' => $shipment->payment_method,
            'dest' => $shipment->destination['country'] ?? null,
            'type' => $shipment->customer_type,
            'pkg' => $pkgCount,
            'shipper' => $shipment->origin['company'] ?? $shipment->origin['contact_name'] ?? null,
            'consignee' => $shipment->destination['company'] ?? $shipment->destination['contact_name'] ?? null,
            'freight' => (float) $shipment->freight_amount,
            'sur' => round($sur, 2),
            'accs' => round($accs, 2),
            'ins' => round($insFromApi + $insFromAddon, 2),
            'ins_co' => $insCo,
            'metal' => round($metal, 2),
            'form' => round($form, 2),
            'other' => round($otherFromApi + $otherFromAddon, 2),
            'total_charge' => (float) $shipment->order_total,
            'remark' => $remark,
            'inv_value' => round($invValue, 2),
        ];
    }

    private function shortInsuranceCode(string $name): string
    {
        foreach (['ICDV', 'UPSC', 'DHL'] as $code) {
            if (stripos($name, $code) !== false) {
                return $code;
            }
        }

        return trim(explode(' ', $name)[0] ?? $name);
    }
}
