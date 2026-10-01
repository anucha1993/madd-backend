<?php

namespace App\Services;

use App\Models\ApiRequestLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Usage statistics of the website plugins, from api_request_logs: page views (view_*), searches
 * (rates / web_rates, tracking / web_tracking), unique visitors (distinct visitor IP), outcomes,
 * popular destinations and busy hours. Days and hours are Bangkok time.
 */
class PublicApiStatsService
{
    public const GROUPS = [
        'rates' => ['searches' => ['rates', 'web_rates'], 'views' => ['view_rates']],
        'tracking' => ['searches' => ['tracking', 'web_tracking'], 'views' => ['view_tracking']],
    ];

    private const TZ_OFFSET_HOURS = 7;

    public function build(Carbon $from, Carbon $to, ?int $clientId): array
    {
        $base = fn () => ApiRequestLog::query()
            ->whereBetween('created_at', [$from->copy()->subHours(self::TZ_OFFSET_HOURS), $to->copy()->subHours(self::TZ_OFFSET_HOURS)])
            ->when($clientId, fn ($q) => $q->where('api_client_id', $clientId));

        $summary = [];
        foreach (self::GROUPS as $group => $endpoints) {
            $searches = fn () => $base()->whereIn('endpoint', $endpoints['searches']);
            $views = $base()->whereIn('endpoint', $endpoints['views'])->count();
            $total = $searches()->count();
            $ok = $searches()->where('status_code', 200)->count();
            $summary[$group] = [
                'views' => $views,
                'searches' => $total,
                'visitors' => $base()->whereIn('endpoint', [...$endpoints['searches'], ...$endpoints['views']])->distinct()->count('end_user_ip'),
                'searchers' => $searches()->distinct()->count('end_user_ip'),
                'successful' => $ok,
                'failed' => $total - $ok,
                'cached' => $searches()->where('cached', true)->count(),
                'avg_ms' => (int) round((float) $searches()->where('status_code', 200)->where('cached', false)->avg('duration_ms')),
            ] + ($group === 'rates'
                ? ['no_result' => $searches()->where('status_code', 200)->where('result_count', 0)->count()]
                : [
                    'not_found' => $searches()->where('status_code', 404)->count(),
                    'external' => $searches()->where('status_code', 200)->where('error', 'external')->count(),
                ]);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'daily' => $this->daily($base(), $from, $to),
            'hourly' => $this->hourly($base()),
            'destinations' => $base()->whereIn('endpoint', self::GROUPS['rates']['searches'])->where('status_code', 200)->whereNotNull('destination_country')
                ->groupBy('destination_country')
                ->selectRaw('destination_country as country, COUNT(*) as searches, AVG(lowest_price) as avg_price, AVG(total_weight) as avg_weight')
                ->orderByDesc('searches')->limit(15)->get()
                ->map(fn ($r) => ['country' => $r->country, 'searches' => (int) $r->searches, 'avg_price' => $r->avg_price !== null ? round((float) $r->avg_price, 2) : null, 'avg_weight' => $r->avg_weight !== null ? round((float) $r->avg_weight, 1) : null]),
            'weights' => $this->weights($base()),
        ];
    }

    private function localExpr(string $format): string
    {
        $h = self::TZ_OFFSET_HOURS;

        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('{$format}', created_at, '+{$h} hours')"
            : ($format === '%Y-%m-%d' ? "DATE(DATE_ADD(created_at, INTERVAL {$h} HOUR))" : "HOUR(DATE_ADD(created_at, INTERVAL {$h} HOUR))");
    }

    private function daily(Builder $query, Carbon $from, Carbon $to): array
    {
        $day = $this->localExpr('%Y-%m-%d');
        $rows = $query->groupByRaw("{$day}, endpoint")->selectRaw("{$day} as day, endpoint, COUNT(*) as n, COUNT(DISTINCT end_user_ip) as v")->get();

        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $out[$d->toDateString()] = ['date' => $d->toDateString(), 'rates_views' => 0, 'rates_searches' => 0, 'tracking_views' => 0, 'tracking_searches' => 0];
        }
        foreach ($rows as $r) {
            $key = substr((string) $r->day, 0, 10);
            if (! isset($out[$key])) {
                continue;
            }
            foreach (self::GROUPS as $group => $endpoints) {
                if (in_array($r->endpoint, $endpoints['searches'], true)) {
                    $out[$key]["{$group}_searches"] += (int) $r->n;
                } elseif (in_array($r->endpoint, $endpoints['views'], true)) {
                    $out[$key]["{$group}_views"] += (int) $r->n;
                }
            }
        }

        return array_values($out);
    }

    private function hourly(Builder $query): array
    {
        $hour = $this->localExpr('%H');
        $counts = $query->whereIn('endpoint', [...self::GROUPS['rates']['searches'], ...self::GROUPS['tracking']['searches']])
            ->groupByRaw($hour)->selectRaw("{$hour} as h, COUNT(*) as n")->pluck('n', 'h');
        $out = array_fill(0, 24, 0);
        foreach ($counts as $h => $n) {
            $out[(int) $h] = (int) $n;
        }

        return $out;
    }

    private function weights(Builder $query): array
    {
        $bands = ['< 1 kg' => [0, 1], '1–5 kg' => [1, 5], '5–10 kg' => [5, 10], '10–20 kg' => [10, 20], '20+ kg' => [20, null]];
        $out = [];
        foreach ($bands as $label => [$min, $max]) {
            $q = (clone $query)->whereIn('endpoint', self::GROUPS['rates']['searches'])->where('status_code', 200)->where('total_weight', '>=', $min);
            if ($max !== null) {
                $q->where('total_weight', '<', $max);
            }
            $out[] = ['label' => $label, 'searches' => $q->count()];
        }

        return $out;
    }
}
