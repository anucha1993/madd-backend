<?php

namespace App\Services;

use App\Models\Country;
use App\Models\HeavyRateBand;
use App\Models\IntegrationSetting;
use Illuminate\Support\Collection;

/**
 * Heavy Weight sell rates (/config/heavy-rates): from the first band's weight up (71 kg), the sell
 * price is staff's own THB per kg × chargeable weight — by band × zone (the destination's UPS / DHL
 * zone from /config/countries; a zone without its own price uses the band's default). It replaces
 * the charge lines picked per carrier (replacedCodes — by default freight + fuel); every other line
 * (remote area, demand surcharge …) stays at its normal sell price, everywhere quotes are priced
 * (ChargeMarkupService).
 */
class HeavyRateService
{
    public const REPLACED_CODES_KEY = 'heavy_rate.replaced_codes';

    // Freight + fuel: what a per-kg price stands for. Everything else is still charged on top.
    public const DEFAULT_REPLACED_CODES = ['UPS' => ['BASE', '375'], 'DHL' => ['BASE', 'FF']];

    /** @var array<string, list<string>>|null */
    private ?array $replacedCodes = null;

    /** @var Collection<string, Collection<int, HeavyRateBand>>|null */
    private ?Collection $bands = null;

    /** @var array<string, ?string> */
    private array $zones = [];

    /** @return array{rate: float, amount: float, weight: float, band: string, scope: string, zone: ?string}|null */
    public function resolve(string $carrier, ?string $countryIso2, float $weight): ?array
    {
        $carrier = strtoupper($carrier);
        $bands = $this->bands()->get($carrier);
        if (! $bands || $bands->isEmpty() || $weight <= 0 || $weight < $bands->first()->min_weight - 0.005) {
            return null;
        }
        // Bands are 71-300, 301+ … — a weight between two (300.5) belongs to the lower one.
        $band = $bands->first(fn ($b) => $b->max_weight === null || $weight <= $b->max_weight + 0.005);
        if (! $band) {
            return null;
        }

        $zone = $this->zone($carrier, $countryIso2);
        $rates = $band->rates ?? [];
        $zoneRate = $zone !== null ? ($rates['zone:'.$zone] ?? null) : null;
        $rate = is_numeric($zoneRate) ? (float) $zoneRate : (is_numeric($rates['all'] ?? null) ? (float) $rates['all'] : null);
        if ($rate === null || $rate <= 0) {
            return null; // no price set → the carrier's own quote, as before
        }

        return [
            'rate' => $rate,
            'amount' => round($rate * $weight, 2),
            'weight' => $weight,
            'band' => self::bandLabel($band->min_weight, $band->max_weight),
            'scope' => is_numeric($zoneRate) ? "Zone {$zone}" : 'ทุก Zone',
            'zone' => $zone,
        ];
    }

    /**
     * Charge codes the Heavy Weight Rate line replaces for this carrier — carrier codes (BASE, 375 …)
     * and our own lines (WEIGHT_MARKUP, a Mark-up rule's line such as TOTAL).
     *
     * @return list<string>
     */
    public function replacedCodes(string $carrier): array
    {
        $this->replacedCodes ??= self::storedReplacedCodes();

        return $this->replacedCodes[strtoupper($carrier)] ?? [];
    }

    /** @return array<string, list<string>> */
    public static function storedReplacedCodes(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::REPLACED_CODES_KEY), true);

        return is_array($saved) ? $saved + self::DEFAULT_REPLACED_CODES : self::DEFAULT_REPLACED_CODES;
    }

    public static function bandLabel(float $min, ?float $max): string
    {
        $fmt = fn (float $w) => rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.');

        return $fmt($min).($max !== null ? '-'.$fmt($max) : '+').' kg';
    }

    private function zone(string $carrier, ?string $countryIso2): ?string
    {
        if (! $countryIso2 || ! in_array($carrier, ['UPS', 'DHL'], true)) {
            return null;
        }
        $key = $carrier.'|'.strtoupper($countryIso2);
        if (! array_key_exists($key, $this->zones)) {
            $zone = Country::where('iso2', strtoupper($countryIso2))->value(strtolower($carrier).'_zone');
            $this->zones[$key] = $zone !== null && $zone !== '' ? strtoupper((string) $zone) : null;
        }

        return $this->zones[$key];
    }

    /** Loaded once per instance (one rate check / sync), not per quote. */
    private function bands(): Collection
    {
        return $this->bands ??= HeavyRateBand::orderBy('sort_order')->orderBy('min_weight')->get()->groupBy('carrier');
    }
}
