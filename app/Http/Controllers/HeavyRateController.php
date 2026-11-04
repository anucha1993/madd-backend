<?php

namespace App\Http\Controllers;

use App\Models\ChargeCode;
use App\Models\HeavyRateBand;
use App\Models\IntegrationSetting;
use App\Models\Shipment;
use App\Services\ChargeMarkupService;
use App\Services\HeavyRateService;
use App\Services\UpsRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /config/heavy-rates — per carrier: weight bands (from 71 kg up) × zones, cells = sell THB per
 * kg. Saved as a whole, like Weight Markup.
 */
class HeavyRateController extends Controller
{
    public function show(Request $request)
    {
        $carrier = $request->validate(['carrier' => ['required', 'in:UPS,DHL']])['carrier'];

        return response()->json([
            'bands' => HeavyRateBand::where('carrier', $carrier)->orderBy('sort_order')->orderBy('min_weight')->get()
                ->map(fn ($b) => ['min_weight' => $b->min_weight, 'max_weight' => $b->max_weight, 'rates' => (object) ($b->rates ?? [])]),
            // Lines the Heavy Weight Rate replaces (the rest stay at their normal sell price).
            'replaced_codes' => HeavyRateService::storedReplacedCodes()[$carrier] ?? [],
            // Services each special-country price is for ({"AU": ["65"]}; none = every service).
            'country_services' => (object) (HeavyRateService::storedCountryServices()[$carrier] ?? []),
            'services' => self::services($carrier),
            // Every line a quote can carry: the carrier's charge codes + our own lines.
            'codes' => [
                ['code' => 'WEIGHT_MARKUP', 'label' => 'Weight Markup (บวกตามช่วงน้ำหนัก)'],
                ...ChargeCode::where('provider', $carrier)
                    ->whereNotIn('code', [...ChargeMarkupService::COST_ONLY_CODES, ...self::FORMULA_ONLY_CODES])
                    ->orderByRaw("CASE WHEN code = 'BASE' THEN 0 ELSE 1 END")->orderBy('code')
                    ->get(['code', 'label'])->map(fn ($c) => ['code' => (string) $c->code, 'label' => $c->label])->all(),
            ],
        ]);
    }

    /**
     * Services to pick for a special-country price: UPS's fixed Rating codes; DHL's come from its
     * API per quote, so the ones booked so far plus the usual Express Worldwide.
     *
     * @return list<array{code: string, label: string}>
     */
    private static function services(string $carrier): array
    {
        $services = $carrier === 'UPS'
            ? UpsRateService::SERVICE_CODES
            : ['P' => 'EXPRESS WORLDWIDE'] + Shipment::where('carrier', 'DHL')
                ->whereNotNull('service_code')->distinct()->pluck('service_label', 'service_code')->filter()->all();
        foreach (HeavyRateService::storedCountryServices()[$carrier] ?? [] as $codes) {
            foreach ($codes as $code) {
                $services[$code] ??= $code;
            }
        }

        return collect($services)->map(fn ($label, $code) => ['code' => (string) $code, 'label' => (string) $label])->values()->all();
    }

    // Formula variables, never a line on a quote.
    private const FORMULA_ONLY_CODES = ['BILLED_WEIGHT', 'W', 'BOX', 'COST'];

    /** Which lines the Heavy Weight Rate replaces, per carrier. */
    public function saveCodes(Request $request)
    {
        $data = $request->validate([
            'carrier' => ['required', 'in:UPS,DHL'],
            'replaced_codes' => ['present', 'array'],
            'replaced_codes.*' => ['string', 'max:50'],
        ]);
        $all = HeavyRateService::storedReplacedCodes();
        $all[$data['carrier']] = array_values(array_unique(array_map('strval', $data['replaced_codes'])));
        IntegrationSetting::set(HeavyRateService::REPLACED_CODES_KEY, json_encode($all));

        return $this->show($request);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'carrier' => ['required', 'in:UPS,DHL'],
            'bands' => ['present', 'array'],
            'bands.*.min_weight' => ['required', 'numeric', 'min:0'],
            'bands.*.max_weight' => ['nullable', 'numeric', 'min:0'],
            'bands.*.rates' => ['present', 'array'],
            'bands.*.rates.*' => ['nullable', 'numeric', 'min:0'],
            'country_services' => ['sometimes', 'array'],
            'country_services.*' => ['array'],
            'country_services.*.*' => ['string', 'max:10'],
        ], ['bands.*.rates.*.numeric' => 'ราคาต้องเป็นตัวเลข (บาท/kg)', 'bands.*.rates.*.min' => 'ราคาต้องไม่ติดลบ']);

        $bands = collect($data['bands'])->sortBy('min_weight')->values();
        foreach ($bands as $i => $band) {
            if ($band['max_weight'] !== null && (float) $band['max_weight'] <= (float) $band['min_weight']) {
                return response()->json(['message' => "ช่วง {$band['min_weight']}: น้ำหนักสูงสุดต้องมากกว่าต่ำสุด"], 422);
            }
            if ($band['max_weight'] === null && $i < $bands->count() - 1) {
                return response()->json(['message' => 'ช่วงที่ไม่มีน้ำหนักสูงสุด ("ขึ้นไป") ต้องเป็นช่วงสุดท้าย'], 422);
            }
            if ($i > 0 && (float) $band['min_weight'] <= (float) $bands[$i - 1]['max_weight']) {
                return response()->json(['message' => "ช่วง {$band['min_weight']}: ทับกับช่วงก่อนหน้า"], 422);
            }
            foreach (array_keys($band['rates']) as $key) {
                if (! preg_match('/^(all|zone:[A-Z0-9-]{1,10}|country:[A-Z]{2})$/', (string) $key)) {
                    return response()->json(['message' => "คอลัมน์ไม่ถูกต้อง: {$key}"], 422);
                }
            }
        }

        if (array_key_exists('country_services', $data)) {
            $all = HeavyRateService::storedCountryServices();
            $all[$data['carrier']] = collect($data['country_services'])
                ->filter(fn ($codes, $iso2) => preg_match('/^[A-Z]{2}$/', (string) $iso2) && $codes)
                ->map(fn ($codes) => array_values(array_unique(array_map('strval', $codes))))
                ->all();
            IntegrationSetting::set(HeavyRateService::COUNTRY_SERVICES_KEY, json_encode($all));
        }

        DB::transaction(function () use ($data, $bands) {
            HeavyRateBand::where('carrier', $data['carrier'])->get()->each->delete();
            foreach ($bands as $i => $band) {
                HeavyRateBand::create([
                    'carrier' => $data['carrier'],
                    'min_weight' => $band['min_weight'],
                    'max_weight' => $band['max_weight'],
                    'rates' => collect($band['rates'])->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (float) $v)->all(),
                    'sort_order' => $i,
                ]);
            }
        });

        return $this->show($request);
    }
}
