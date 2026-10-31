<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\RateBookRun;
use App\Support\RateBookRateCard;
use App\Support\RateBookSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/public/v1/rate-book — the latest finished Rate Book as customer rate cards for an
 * external site (MADD Rate Book WordPress plugin). One "book" per carrier × service × document/box:
 * columns = zones (+ extra columns like JP / AU / USA PR) with their countries, rows = the rate
 * card's lines (step weights, per-kg bands kg by kg up to 70 kg, then per-kg rates) at the SELL
 * price — the same price the Check Rate API gives, rounded with the key's price_rounding.
 * No cost, markup, account or charge breakdown leaves this endpoint.
 */
class RateBookController extends Controller
{
    private const CACHE_HOURS = 6;

    public function show(Request $request)
    {
        $startedAt = microtime(true);
        /** @var ApiClient $client */
        $client = $request->attributes->get('api_client');
        $log = ['api_client_id' => $client->id, 'endpoint' => 'rate_book', 'ip' => $request->ip(), 'end_user_ip' => $request->attributes->get('end_user_ip')];
        if (! $client->allow_rate_book) {
            $this->log($log + ['status_code' => 403, 'error' => 'endpoint_not_allowed'], $startedAt);

            return response()->json(['error' => ['code' => 'endpoint_not_allowed', 'message' => 'API key นี้ไม่ได้เปิดใช้ Rate Book']], 403);
        }

        $run = RateBookRun::whereIn('status', ['success', 'partial'])->whereNotNull('finished_at')->latest('finished_at')->first();
        if (! $run) {
            $this->log($log + ['status_code' => 200, 'error' => 'no_rate_book'], $startedAt);

            return response()->json(['updated_at' => null, 'books' => []]);
        }

        // A new run, or a change to the key (carriers / rounding), gives a new cache entry.
        $key = 'public-rate-book:'.$client->id.':'.$client->updated_at?->timestamp.':'.$run->id;
        $data = Cache::remember($key, now()->addHours(self::CACHE_HOURS), fn () => $this->build($run, $client));
        $this->log($log + ['status_code' => 200], $startedAt);

        return response()->json($data);
    }

    private function build(RateBookRun $run, ApiClient $client): array
    {
        $settings = $run->settings ?? [];
        $carriers = array_values(array_intersect(RateBookSettings::CARRIERS, $client->carriers ?: RateBookSettings::CARRIERS));
        $books = [];
        foreach ($carriers as $carrier) {
            $rows = $run->rows()->where('carrier', $carrier)->orderBy('id')
                ->get(['package_type', 'zone', 'band_label', 'weight', 'is_per_kg', 'service_code', 'sell', 'error']);
            if ($rows->isEmpty()) {
                continue;
            }
            $columns = collect(RateBookSettings::columns($settings, $carrier))->filter(fn ($c) => $rows->contains('zone', $c['key']))->values()->all();
            $countries = $this->countries($carrier, $columns);
            $services = RateBookSettings::services($settings, $carrier);
            foreach ($services as $service) {
                $serviceRows = $rows->filter(fn ($r) => RateBookSettings::serviceOf($services, $r->service_code)['key'] === $service['key']);
                // An extra service is priced for its own sub-columns only (e.g. Eco JP, Eco/US CA).
                $serviceColumns = $service['main'] ? array_map(fn ($c) => ['source' => $c['key'], 'label' => $c['label']], $columns)
                    : array_map(fn ($c) => ['source' => $c['source'], 'label' => $c['label'] === $service['label'] ? $this->columnLabel($columns, $c['under']) : $c['label']], RateBookSettings::subColumns($service, $columns));
                foreach (RateBookSettings::PACKAGE_TYPES as $type) {
                    $book = $this->book($carrier, $service, $type, $serviceRows->where('package_type', $type), $serviceColumns, $countries, $client->price_rounding);
                    if ($book) {
                        $books[] = $book;
                    }
                }
            }
        }

        return ['updated_at' => ($run->finished_at ?? $run->created_at)->format('j M Y'), 'books' => $books];
    }

    private function book(string $carrier, array $service, string $type, Collection $typeRows, array $columns, array $countries, int $rounding): ?array
    {
        if ($typeRows->isEmpty() || ! $columns) {
            return null;
        }
        $rows = [];
        foreach (RateBookRateCard::lines($typeRows) as $line) {
            $prices = array_map(function ($column) use ($typeRows, $line, $rounding) {
                $row = $typeRows->first(fn ($r) => $r->band_label.'|'.$r->weight === $line['source'] && (string) $r->zone === $column['source']);

                return $row && ! $row->error && $row->sell !== null ? $this->round((float) $row->sell * $line['mult'], $rounding) : null;
            }, $columns);
            if (array_filter($prices, fn ($p) => $p !== null)) {
                $rows[] = ['label' => $this->lineLabel($line), 'prices' => $prices];
            }
        }
        if (! $rows) {
            return null;
        }

        return [
            'id' => strtolower($carrier.'-'.$service['key'].'-'.$type),
            'title' => $carrier.' '.$service['label'].' — '.($type === 'document' ? 'Document' : 'Parcel'),
            'note' => $type === 'box' ? '*800 THB or 30 THB / kg may be charged for remote areas depending on destination postal code or city.' : '',
            'zones' => array_map(fn ($c) => ['name' => $c['label'], 'countries' => $countries[$c['source']] ?? []], $columns),
            'rows' => $rows,
        ];
    }

    /**
     * Countries per column. An extra column (JP, AU, USA PR …) is its own country, and that country
     * is left out of its zone's list — its price is the extra column's.
     */
    private function countries(string $carrier, array $columns): array
    {
        $own = array_values(array_filter(array_map(fn ($c) => $c['extra'] ? ($c['address']['iso2'] ?? null) : null, $columns)));
        $clean = fn ($name) => trim(preg_replace('/\s+\S*[\x{3400}-\x{9FFF}]\S*$/u', '', (string) $name));
        $zoneColumn = strtolower($carrier).'_zone';
        $list = [];
        foreach ($columns as $c) {
            $query = DB::table('countries')->where('status', true);
            $c['extra'] ? $query->where('iso2', $c['address']['iso2'] ?? '') : $query->where($zoneColumn, $c['zone'])->whereNotIn('iso2', $own);
            $list[$c['key']] = $query->orderBy('name')->pluck('name')->map($clean)->filter()->values()->all();
        }

        return $list;
    }

    private function columnLabel(array $columns, string $key): string
    {
        foreach ($columns as $c) {
            if ($c['key'] === $key) {
                return $c['label'];
            }
        }

        return $key;
    }

    private function lineLabel(array $line): string
    {
        if ($line['rate']) {
            return $line['label'].' (per kg)';
        }

        return rtrim(rtrim(number_format((float) $line['label'], 2, '.', ''), '0'), '.');
    }

    private function round(float $price, int $step): float
    {
        return $step > 0 ? (float) (ceil(round($price, 2) / $step) * $step) : round($price, 2);
    }

    private function log(array $attributes, float $startedAt): void
    {
        try {
            ApiRequestLog::create($attributes + ['duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
