<?php

namespace App\Services;

use App\Models\WeightMarkupBand;
use Illuminate\Support\Collection;

/**
 * Weight Markup (/config/weight-markups): THB per chargeable kg, by weight band, added on top of
 * the sell price after Fixed Charges + Mark-up rules (ChargeMarkupService).
 *
 * Band set: the account's own set if it has one, else the carrier-wide set (per document/box).
 * Rate within the band: country override > zone override > the band's default ("all").
 */
class WeightMarkupService
{
    /** @var Collection<string, Collection<int, WeightMarkupBand>>|null */
    private ?Collection $sets = null;

    /**
     * @return array{rate: float, amount: float, weight: float, band: string, scope: string}|null
     */
    public function resolve(string $carrier, string $packageType, ?int $accountId, ?string $countryIso2, ?string $zone, float $weight): ?array
    {
        if ($weight <= 0) {
            return null;
        }
        $sets = $this->sets();
        $carrier = strtoupper($carrier);
        $bands = $sets->get(self::key($carrier, $packageType, $accountId)) ?? $sets->get(self::key($carrier, $packageType, null));
        if (! $bands) {
            return null;
        }

        // Bands are listed as 0.10-5.00, 5.01-10.00 … — a weight between two (5.005) belongs to the
        // lower one; the first band also catches anything below its minimum.
        $band = $bands->first(fn ($b) => $b->max_weight === null || $weight <= $b->max_weight + 0.005);
        if (! $band) {
            return null;
        }

        $rates = $band->rates->keyBy(fn ($r) => $r->scope_type.':'.strtoupper((string) $r->scope_value));
        $rate = ($countryIso2 ? $rates->get('country:'.strtoupper($countryIso2)) : null)
            ?? ($zone !== null && $zone !== '' ? $rates->get('zone:'.strtoupper($zone)) : null)
            ?? $rates->get('all:');
        if (! $rate || (float) $rate->rate_per_kg == 0.0) {
            return null;
        }

        return [
            'rate' => (float) $rate->rate_per_kg,
            'amount' => round((float) $rate->rate_per_kg * $weight, 2),
            'weight' => $weight,
            'band' => number_format($band->min_weight, 2).($band->max_weight !== null ? '-'.number_format($band->max_weight, 2) : '+').' kg',
            'scope' => $rate->scope_type === 'all' ? 'ทุกประเทศ' : ($rate->scope_type === 'zone' ? "Zone {$rate->scope_value}" : (string) $rate->scope_value),
        ];
    }

    /** Loaded once per instance (one rate check / sync), not per quote. */
    private function sets(): Collection
    {
        return $this->sets ??= WeightMarkupBand::with('rates')->orderBy('sort_order')->orderBy('min_weight')->get()
            ->groupBy(fn ($b) => self::key($b->carrier, $b->package_type, $b->agent_account_id));
    }

    private static function key(string $carrier, string $packageType, ?int $accountId): string
    {
        return strtoupper($carrier).'|'.$packageType.'|'.($accountId ?? '*');
    }
}
