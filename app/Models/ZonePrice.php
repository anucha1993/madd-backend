<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff's own price for one charge code per manual zone (countries.ups_zone / dhl_zone), with
 * optional single-country rows that differ from the rest of their zone. Used in Fixed Charges /
 * Markup Rules formulas as {ZONE_PRICE} (see ChargeMarkupService).
 */
#[Fillable(['carrier', 'charge_code', 'zone', 'country_iso2', 'price', 'note'])]
class ZonePrice extends Model
{
    use Auditable;

    public const CARRIERS = ['UPS', 'DHL'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
        ];
    }

    /** The carrier's zone column on countries — 'ups_zone' / 'dhl_zone'. */
    public static function zoneColumn(string $carrier): ?string
    {
        $carrier = strtoupper($carrier);

        return in_array($carrier, self::CARRIERS, true) ? strtolower($carrier).'_zone' : null;
    }

    /**
     * Every configured price for one carrier + destination country, keyed by charge code — a
     * country-specific row wins over its zone's row. Also returns the country's manual zone.
     *
     * @return array{zone: ?string, prices: array<string, float>}
     */
    public static function lookup(?string $carrier, ?string $countryIso2): array
    {
        $column = $carrier ? self::zoneColumn($carrier) : null;
        if (! $column || ! $countryIso2) {
            return ['zone' => null, 'prices' => []];
        }

        $iso2 = strtoupper($countryIso2);
        $zone = Country::where('iso2', $iso2)->value($column);

        $rows = static::query()
            ->where('carrier', strtoupper($carrier))
            ->where(fn ($q) => $q->where('country_iso2', $iso2)->when($zone !== null && $zone !== '', fn ($z) => $z->orWhere(fn ($w) => $w->whereNull('country_iso2')->where('zone', $zone))))
            ->get();

        $prices = [];
        // Zone rows first, then country rows overwrite them.
        foreach ($rows->sortBy(fn ($r) => $r->country_iso2 === null ? 0 : 1) as $row) {
            $prices[(string) $row->charge_code] = (float) $row->price;
        }

        return ['zone' => $zone ?: null, 'prices' => $prices];
    }
}
