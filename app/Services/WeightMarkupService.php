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
 * A cell is either THB per kg (× chargeable weight) or a formula — e.g. "{BASE}*5%" — evaluated by
 * ChargeFormulaEvaluator against the quote's values; a formula's result is THB for the shipment.
 */
class WeightMarkupService
{
    /** @var Collection<string, Collection<int, WeightMarkupBand>>|null */
    private ?Collection $sets = null;

    /**
     * @param  array<string, float>  $values  formula variables: the carrier's own amount per charge
     *         code ({BASE}, {375} …) plus W, BILLED_WEIGHT, TOTAL (final total so far), COST, BOX, ZONE
     * @param  array{package_weights?: array<int, float>}  $context  for BOX_OVER() etc.
     * @return array{rate: ?float, formula: ?string, amount: float, weight: float, band: string, scope: string}|null
     */
    public function resolve(string $carrier, string $packageType, ?int $accountId, ?string $countryIso2, ?string $zone, float $weight, array $values = [], array $context = []): ?array
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
        if (! $rate) {
            return null;
        }
        if ($rate->formula) {
            try {
                $amount = round(ChargeFormulaEvaluator::evaluate($rate->formula, $values + ['W' => $weight], $context), 2);
            } catch (\Throwable) {
                // A formula this quote can't resolve (e.g. a code the carrier didn't return) adds nothing.
                return null;
            }
        } else {
            $amount = round((float) $rate->rate_per_kg * $weight, 2);
        }
        if ($amount == 0.0) {
            return null;
        }

        return [
            'rate' => $rate->formula ? null : (float) $rate->rate_per_kg,
            'formula' => $rate->formula,
            'amount' => $amount,
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
