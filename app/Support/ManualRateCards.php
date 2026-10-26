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

        // Cards saved before the destination country existed: "Japan" → JP.
        return array_map(fn ($c) => $c + ['country' => preg_match('/japan/i', (string) ($c['destination'] ?? '')) ? 'JP' : ''],
            is_array($saved) ? $saved : self::defaults());
    }

    /**
     * Check Rate quotes from the cards for this destination — sell prices, quote only (`quoteOnly`):
     * no account, no quoteId, never bookable (bookings only take UPS / DHL).
     */
    public static function quotes(string $countryIso2, float $billedWeight): array
    {
        $quotes = [];
        foreach (self::get() as $card) {
            if (strcasecmp((string) $card['country'], $countryIso2) !== 0) {
                continue;
            }
            [$price, $weight] = self::price($card, $billedWeight);
            if ($price === null) {
                continue;
            }
            $quotes[] = [
                'carrier' => $card['name'], 'accountId' => 0, 'username' => '', 'serviceCode' => $card['key'],
                'serviceLabel' => $card['name'].' (ราคาตามตาราง)', 'currency' => $card['currency'],
                'negotiated' => $price, 'published' => $price, 'billedWeight' => $weight, 'billedWeightUnit' => 'KGS',
                'chargeBreakdown' => [], 'error' => null, 'quoteOnly' => true,
            ];
        }

        return $quotes;
    }

    /**
     * A card's price for a weight: the next listed step up, else the whole kg × its per-kg band
     * (same as manualCardPrice() in the frontend). @return array{0: ?float, 1: float} price, weight sold
     */
    public static function price(array $card, float $weight): array
    {
        $step = collect($card['steps'])->sortBy('weight')->first(fn ($s) => (float) $s['weight'] >= $weight - 1e-9);
        if ($step) {
            return [(float) $step['price'], (float) $step['weight']];
        }
        $kg = (float) ceil($weight - 1e-9);
        $band = collect($card['per_kg'])->first(fn ($b) => $kg >= (float) $b['min'] && ($b['max'] === null || $kg <= (float) $b['max']));

        return [$band ? round($kg * (float) $band['price'], 2) : null, $kg];
    }

    public static function save(array $cards): array
    {
        $cards = array_values(array_map(fn ($c) => [
            'key' => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) ($c['key'] ?? $c['name'])))),
            'name' => trim((string) $c['name']),
            'destination' => trim((string) ($c['destination'] ?? '')),
            // Shown in Check Rate for shipments to this country (ISO2, e.g. JP); "" = Rate Book only.
            'country' => strtoupper(trim((string) ($c['country'] ?? ''))),
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
            'country' => 'JP',
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
