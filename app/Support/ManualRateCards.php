<?php

namespace App\Support;

use App\Models\IntegrationSetting;

/**
 * Rate cards typed in by hand for brands with no API (e.g. SAGAWA, Japan only) — shown in the Rate
 * Book next to UPS / DHL so staff can check a price; nothing is ever booked through them.
 *
 * Each card: step prices (a price per listed weight, e.g. 0.5 … 10 every 0.5, then 11 … 20 every
 * 1 kg) and per-kg bands above them (e.g. 21–50 kg = 169 THB/kg). A weight between two steps is
 * sold at the next step up; above the steps, at the whole kg × its band's rate.
 */
final class ManualRateCards
{
    private const KEY = 'rate_book.manual_cards';

    /** @return list<array> */
    public static function get(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        return is_array($saved) ? $saved : self::defaults();
    }

    public static function save(array $cards): array
    {
        $cards = array_values(array_map(fn ($c) => [
            'key' => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) ($c['key'] ?? $c['name'])))),
            'name' => trim((string) $c['name']),
            'destination' => trim((string) ($c['destination'] ?? '')),
            'currency' => strtoupper(trim((string) ($c['currency'] ?? 'THB'))) ?: 'THB',
            'note' => trim((string) ($c['note'] ?? '')),
            'steps' => collect($c['steps'] ?? [])->map(fn ($s) => ['weight' => round((float) $s['weight'], 2), 'price' => round((float) $s['price'], 2)])
                ->sortBy('weight')->unique('weight')->values()->all(),
            'per_kg' => collect($c['per_kg'] ?? [])->map(fn ($b) => [
                'min' => round((float) $b['min'], 2),
                'max' => isset($b['max']) && $b['max'] !== '' ? round((float) $b['max'], 2) : null,
                'price' => round((float) $b['price'], 2),
            ])->sortBy('min')->values()->all(),
        ], $cards));
        IntegrationSetting::set(self::KEY, json_encode($cards, JSON_UNESCAPED_UNICODE));

        return $cards;
    }

    /** SAGAWA's "Special Rate from Thailand to Japan" sheet (START FROM 1-30 JUNE 2026). */
    private static function defaults(): array
    {
        // [weight, price] pairs — float array keys would be cut to ints by PHP (0.5 → 0).
        $steps = [
            [0.5, 1150], [1.0, 1260], [1.5, 1380], [2.0, 1510], [2.5, 1580], [3.0, 1650], [3.5, 1670], [4.0, 1690],
            [4.5, 1810], [5.0, 1930], [5.5, 1990], [6.0, 2060], [6.5, 2140], [7.0, 2220], [7.5, 2300], [8.0, 2380],
            [8.5, 2460], [9.0, 2540], [9.5, 2620], [10.0, 2700], [11.0, 2810], [12.0, 3310], [13.0, 3380], [14.0, 3500],
            [15.0, 3610], [16.0, 3720], [17.0, 3830], [18.0, 3940], [19.0, 4050], [20.0, 4160],
        ];

        return [[
            'key' => 'SAGAWA',
            'name' => 'SAGAWA',
            'destination' => 'Japan',
            'currency' => 'THB',
            'note' => 'Special Rate from Thailand to Japan · START FROM 1-30 JUNE 2026',
            'steps' => array_map(fn ($s) => ['weight' => (float) $s[0], 'price' => (float) $s[1]], $steps),
            'per_kg' => [
                ['min' => 21, 'max' => 50, 'price' => 169],
                ['min' => 51, 'max' => 70, 'price' => 158],
                ['min' => 71, 'max' => 100, 'price' => 118],
                ['min' => 101, 'max' => 150, 'price' => 108],
                ['min' => 151, 'max' => 200, 'price' => 104],
                ['min' => 201, 'max' => null, 'price' => 99],
            ],
        ]];
    }
}
