<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shipment Analytics (Reports › /reports/summary): volume and operations over a date range,
 * compared with the previous period of the same length. Always limited to what the viewer's
 * `shipment` data scope can see and excludes Test-mode bookings; revenue only when the viewer
 * may see the shipment `pricing` field group. Money is the sell price (order_total) — cost and
 * margin belong to the finance report.
 */
class ShipmentAnalyticsService
{
    private const TZ = 'Asia/Bangkok';

    public function __construct(private AccessService $access) {}

    /** @param array{branch_id?:?int, carrier?:?string, created_by?:?int} $filters */
    public function build(User $user, Carbon $from, Carbon $to, string $group, array $filters): array
    {
        $showRevenue = $this->access->fieldLevel($user, 'shipment', 'pricing') !== 'hidden';
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        $current = $this->rows($user, $from, $to, $filters);
        $previous = $this->rows($user, $prevFrom, $prevTo, $filters);

        $kpis = $this->kpis($current);
        $prevKpis = $this->kpis($previous);
        if (! $showRevenue) {
            unset($kpis['revenue'], $kpis['avg_revenue'], $prevKpis['revenue'], $prevKpis['avg_revenue']);
        }

        $booked = $current->where('status', 'booked');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'group' => $group,
            'show_revenue' => $showRevenue,
            'kpis' => $kpis,
            'previous_kpis' => $prevKpis,
            'trend' => $this->trend($current, $from, $to, $group, $showRevenue),
            'carriers' => $this->breakdown($booked, fn ($s) => $s->carrier.'|'.($s->service_label ?: $s->service_code), $showRevenue)
                ->map(function ($r) {
                    [$carrier, $service] = explode('|', $r['key'], 2);

                    return ['carrier' => $carrier, 'service' => $service] + $r;
                })->values(),
            'destinations' => $this->breakdown($booked, fn ($s) => $s->destination['country'] ?? '—', $showRevenue)->take(15)->values(),
            'branches' => $this->breakdown($current, fn ($s) => (string) ($s->branch_id ?? '0'), $showRevenue, true)
                ->map(fn ($r) => $r + ['name' => $r['key'] === '0' ? '—' : ($current->firstWhere('branch_id', (int) $r['key'])?->branch?->name ?? '#'.$r['key'])])->values(),
            'staff' => $this->breakdown($current, fn ($s) => (string) ($s->created_by ?? '0'), $showRevenue, true)
                ->map(fn ($r) => $r + ['name' => $r['key'] === '0' ? '—' : ($current->firstWhere('created_by', (int) $r['key'])?->creator?->name ?? '#'.$r['key'])])->values(),
            'attention' => $this->attention($user, $filters),
        ];
    }

    private function base(User $user, array $filters): Builder
    {
        return Shipment::visibleTo($user)
            ->whereIn('status', ['booked', 'voided'])
            ->whereHas('agentAccount', fn ($q) => $q->where('mode', '!=', 'test'))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['carrier'] ?? null, fn ($q, $v) => $q->where('carrier', $v))
            ->when($filters['created_by'] ?? null, fn ($q, $v) => $q->where('created_by', $v));
    }

    private function rows(User $user, Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->base($user, $filters)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->with(['branch:id,name', 'creator:id,name'])
            ->get(['id', 'status', 'carrier', 'service_code', 'service_label', 'branch_id', 'created_by', 'destination', 'packages', 'order_total', 'tracking_status', 'picked_up_at', 'delivered_at', 'created_at'])
            ->each(function (Shipment $s) {
                $pieces = 0;
                $weight = 0.0;
                foreach ($s->packages ?? [] as $p) {
                    $qty = max(1, (int) ($p['quantity'] ?? 1));
                    $pieces += $qty;
                    $weight += (float) ($p['weight'] ?? 0) * $qty;
                }
                $s->setAttribute('_pieces', $pieces);
                $s->setAttribute('_weight', $weight);
            });
    }

    private function kpis(Collection $rows): array
    {
        $booked = $rows->where('status', 'booked');
        $count = $booked->count();
        $transit = $booked->filter(fn ($s) => $s->picked_up_at && $s->delivered_at)
            ->map(fn ($s) => max(0, $s->picked_up_at->diffInHours($s->delivered_at) / 24));

        return [
            'shipments' => $count,
            'pieces' => (int) $booked->sum('_pieces'),
            'weight' => round((float) $booked->sum('_weight'), 1),
            'revenue' => round((float) $booked->sum('order_total'), 2),
            'avg_revenue' => $count ? round((float) $booked->sum('order_total') / $count, 2) : 0,
            'delivered_rate' => $count ? round($booked->where('tracking_status', 'delivered')->count() / $count * 100, 1) : 0,
            'void_rate' => $rows->count() ? round($rows->where('status', 'voided')->count() / $rows->count() * 100, 1) : 0,
            'voided' => $rows->where('status', 'voided')->count(),
            'avg_transit_days' => $transit->count() ? round($transit->avg(), 1) : null,
        ];
    }

    private function bucket(Carbon $date, string $group): string
    {
        $local = $date->copy()->setTimezone(self::TZ);

        return match ($group) {
            'month' => $local->format('Y-m'),
            'week' => $local->startOfWeek(Carbon::MONDAY)->toDateString(),
            default => $local->toDateString(),
        };
    }

    private function trend(Collection $rows, Carbon $from, Carbon $to, string $group, bool $showRevenue): array
    {
        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d = $group === 'month' ? $d->addMonthNoOverflow()->startOfMonth() : ($group === 'week' ? $d->addWeek() : $d->addDay())) {
            $key = $this->bucket($d, $group);
            $out[$key] ??= ['period' => $key, 'DHL' => 0, 'UPS' => 0, 'voided' => 0] + ($showRevenue ? ['revenue' => 0.0] : []);
        }
        foreach ($rows as $s) {
            $key = $this->bucket($s->created_at, $group);
            if (! isset($out[$key])) {
                continue;
            }
            if ($s->status === 'voided') {
                $out[$key]['voided']++;

                continue;
            }
            $out[$key][$s->carrier === 'UPS' ? 'UPS' : 'DHL']++;
            if ($showRevenue) {
                $out[$key]['revenue'] = round($out[$key]['revenue'] + (float) $s->order_total, 2);
            }
        }

        return array_values($out);
    }

    private function breakdown(Collection $rows, callable $keyFn, bool $showRevenue, bool $withVoided = false): Collection
    {
        return $rows->groupBy($keyFn)->map(function (Collection $group, $key) use ($showRevenue, $withVoided) {
            $booked = $withVoided ? $group->where('status', 'booked') : $group;

            return array_filter([
                'key' => (string) $key,
                'shipments' => $booked->count(),
                'weight' => round((float) $booked->sum('_weight'), 1),
                'revenue' => $showRevenue ? round((float) $booked->sum('order_total'), 2) : null,
                'voided' => $withVoided ? $group->where('status', 'voided')->count() : null,
            ], fn ($v) => $v !== null);
        })->sortByDesc('shipments')->values();
    }

    /** Current state (not bound to the date range) of things someone should act on. */
    private function attention(User $user, array $filters): array
    {
        $now = now();
        $pick = fn (Builder $q) => [
            'count' => (clone $q)->count(),
            'items' => (clone $q)->orderBy('created_at')->limit(10)->get(['id', 'tracking_number', 'carrier', 'created_at', 'destination'])
                ->map(fn ($s) => ['id' => $s->id, 'tracking_number' => $s->tracking_number, 'carrier' => $s->carrier, 'created_at' => $s->created_at?->toIso8601String(), 'country' => $s->destination['country'] ?? null]),
        ];
        $booked = fn () => $this->base($user, $filters)->where('status', 'booked');

        return [
            'awaiting_pickup' => $pick($booked()->whereNull('picked_up_at')->where('created_at', '<', $now->copy()->subDay())->where('created_at', '>', $now->copy()->subDays(30))),
            'slow_transit' => $pick($booked()->whereNotNull('picked_up_at')->where('picked_up_at', '<', $now->copy()->subDays(7))->where(fn ($q) => $q->whereNull('tracking_status')->orWhere('tracking_status', '!=', 'delivered'))->where('created_at', '>', $now->copy()->subDays(60))),
            'not_invoiced' => $pick($booked()->whereDoesntHave('receipts')->where('created_at', '>', $now->copy()->subDays(90))),
            'void_not_notified' => $pick($this->base($user, $filters)->where('status', 'voided')->where('carrier_cancel_status', 'pending')->whereNull('carrier_cancel_requested_at')),
        ];
    }
}
