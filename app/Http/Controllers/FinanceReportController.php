<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Revenue & Expense report (/reports/finance) for booked shipments in a date range.
 *
 * - Revenue: each shipment's sell price (order_total).
 * - Expense: what the carrier actually billed, from the matched Carrier Invoice lines. Until a
 *   shipment's invoice is uploaded, its booking-time cost (cost_amount) stands in as an
 *   estimate, and the report says how many shipments are still estimated.
 * - Only THB amounts are summed. A shipment priced or costed in another currency is counted
 *   separately so nothing is silently mixed.
 * - Test-mode shipments are left out.
 * - Expense / profit are omitted for a Role whose shipment "cost" field is hidden.
 */
class FinanceReportController extends Controller
{
    public function __construct(private AccessService $access)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'carrier' => ['nullable', 'in:UPS,DHL'],
            'group_by' => ['nullable', 'in:month,day,carrier,branch,customer_type'],
        ]);
        $groupBy = $data['group_by'] ?? 'month';
        // Dates are Thai calendar days; created_at is stored in UTC.
        $from = Carbon::parse($data['date_from'] ?? now('Asia/Bangkok')->startOfMonth()->toDateString(), 'Asia/Bangkok')->startOfDay();
        $to = Carbon::parse($data['date_to'] ?? now('Asia/Bangkok')->toDateString(), 'Asia/Bangkok')->endOfDay();
        $showCost = $this->access->fieldLevel($request->user(), 'shipment', 'cost') !== 'hidden';

        $query = Shipment::visibleTo($request->user())
            ->with(['agentAccount:id,mode', 'branch:id,name,code', 'carrierInvoiceLines:id,shipment_id,amount,override_amount'])
            ->where('status', 'booked')
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]);
        if (! empty($data['branch_id'])) {
            $query->where('branch_id', $data['branch_id']);
        }
        if (! empty($data['carrier'])) {
            $query->where('carrier', $data['carrier']);
        }

        $groups = [];
        $total = self::emptyBucket('total', 'รวม');
        foreach ($query->orderBy('created_at')->get() as $s) {
            if ($s->is_test) {
                continue;
            }
            [$key, $label] = self::groupOf($s, $groupBy);
            $groups[$key] ??= self::emptyBucket($key, $label);
            foreach ([&$groups[$key], &$total] as &$bucket) {
                self::add($bucket, $s);
            }
            unset($bucket);
        }
        ksort($groups);

        $finish = fn (array $b) => self::finish($b, $showCost);

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'group_by' => $groupBy,
            'show_cost' => $showCost,
            'summary' => $finish($total),
            'groups' => array_values(array_map($finish, $groups)),
        ]);
    }

    /** @return array{0: string, 1: string} sortable key, display label */
    private static function groupOf(Shipment $s, string $groupBy): array
    {
        $at = $s->created_at->copy()->setTimezone('Asia/Bangkok');

        return match ($groupBy) {
            'day' => [$at->format('Y-m-d'), $at->format('d/m/Y')],
            'carrier' => [(string) $s->carrier, (string) $s->carrier],
            'branch' => [$s->branch ? 'b'.str_pad((string) $s->branch->id, 6, '0', STR_PAD_LEFT) : 'zz', $s->branch?->name ?? 'ไม่ระบุสาขา'],
            'customer_type' => [$s->customer_type ?: 'zz', $s->customer_type ?: 'ไม่ระบุ'],
            default => [$at->format('Y-m'), $at->format('m/Y')],
        };
    }

    private static function emptyBucket(string $key, string $label): array
    {
        return [
            'key' => $key, 'label' => $label, 'shipments' => 0, 'revenue' => 0.0,
            'cost_invoiced' => 0.0, 'cost_estimated' => 0.0, 'invoiced_count' => 0, 'estimated_count' => 0,
            'no_cost_count' => 0, 'other_currency_count' => 0,
        ];
    }

    private static function add(array &$b, Shipment $s): void
    {
        $b['shipments']++;
        if (strtoupper((string) ($s->currency ?: 'THB')) !== 'THB') {
            $b['other_currency_count']++;

            return;
        }
        $b['revenue'] += (float) $s->order_total;

        if ($s->carrierInvoiceLines->isNotEmpty()) {
            $b['cost_invoiced'] += (float) $s->carrierInvoiceLines->sum(fn ($l) => (float) ($l->override_amount ?? $l->amount));
            $b['invoiced_count']++;
        } elseif ($s->cost_amount !== null && strtoupper((string) ($s->cost_currency ?: 'THB')) === 'THB') {
            $b['cost_estimated'] += (float) $s->cost_amount;
            $b['estimated_count']++;
        } else {
            $b['no_cost_count']++;
        }
    }

    private static function finish(array $b, bool $showCost): array
    {
        $b['revenue'] = round($b['revenue'], 2);
        if (! $showCost) {
            foreach (['cost_invoiced', 'cost_estimated', 'invoiced_count', 'estimated_count', 'no_cost_count'] as $k) {
                unset($b[$k]);
            }

            return $b;
        }
        $expense = $b['cost_invoiced'] + $b['cost_estimated'];
        $b['cost_invoiced'] = round($b['cost_invoiced'], 2);
        $b['cost_estimated'] = round($b['cost_estimated'], 2);
        $b['expense'] = round($expense, 2);
        $b['profit'] = round($b['revenue'] - $expense, 2);
        $b['margin'] = $b['revenue'] > 0 ? round(($b['revenue'] - $expense) / $b['revenue'] * 100, 2) : null;

        return $b;
    }
}
