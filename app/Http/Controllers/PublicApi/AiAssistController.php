<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Services\OpenAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/public/v1/web/ai-parse — turns a visitor's sentence on the Rate Quote page
 * ("2 boxes of clothes, 5 kg each, to Tokyo" / "iPhone + charger to Sydney") into form values:
 * destination, shipment type and packages — estimating weight / box size from the items when
 * they aren't given. It never returns or invents prices: the page still asks the normal rates
 * endpoint with these values.
 *
 * Spend is capped per visitor IP (per minute and per day) and per site per day; identical
 * sentences are answered from cache for a day.
 */
class AiAssistController extends Controller
{
    private const PER_IP_PER_MINUTE = 5;

    private const PER_IP_PER_DAY = 40;

    private const PER_SITE_PER_DAY = 1500;

    private const PROMPT = <<<'PROMPT'
        You help a Thai international courier (UPS / DHL, shipping FROM Thailand) fill in its
        online rate-quote form. Read the customer's message (Thai or English) and respond with
        ONLY a JSON object:
        {
          "destination_country_iso2": string|null,   // ISO 3166-1 alpha-2, never "TH"
          "destination_city": string|null,
          "destination_postal_code": string|null,
          "shipment_type": "parcel"|"document",
          "packages": [
            { "weight_kg": number, "length_cm": number|null, "width_cm": number|null,
              "height_cm": number|null, "quantity": integer, "estimated": boolean, "item": string|null }
          ],
          "note": string|null,      // ONE short sentence, only when you estimated something
          "understood": boolean     // false when the message is not about sending something abroad
        }
        Rules:
        - Use the customer's own numbers when given (convert g → kg, inches → cm, lb → kg).
        - When weight or box size is NOT given but the items are, ESTIMATE realistic packed values
          (product + packaging + a suitable carton) and set "estimated": true for that package.
          Examples: a smartphone with charger ≈ 0.5 kg in 20×15×8 cm; a pair of shoes ≈ 1.2 kg in
          35×25×13 cm; a T-shirt ≈ 0.25 kg each; a 1 kg bag of coffee ≈ 1.1 kg.
        - A package is ONE box or envelope handed to the courier. "quantity" counts identical
          boxes/envelopes — NEVER items, pairs, pieces or sheets inside them.
        - When the customer lists items without saying how they are boxed, assume everything goes
          into ONE box: a single package whose weight is the combined estimate and whose
          dimensions fit all the items. Only split into several packages when the customer says so
          ("2 boxes", "3 parcels", "กล่องละ ...").
        - Documents / papers / letters / contracts → "shipment_type": "document", ONE envelope
          (unless several envelopes are stated), weight ≈ 0.1–0.5 kg, dimensions null.
        - Whenever any value is estimated, "note" MUST briefly say what you assumed (e.g. "Estimated
          one box of 3 pairs of shoes + 2 T-shirts ≈ 4.5 kg, 45×35×25 cm.").
        - Max 20 packages, weight 0.1–300 kg, each side 1–300 cm.
        - Never mention, estimate or guess prices, taxes or delivery times.
        - If the destination is unclear, use null. Never invent a city that wasn't mentioned.
        PROMPT;

    public function __construct(private OpenAiService $openAi) {}

    public function parse(Request $request)
    {
        $client = $request->attributes->get('api_client');
        $ip = (string) $request->attributes->get('end_user_ip');
        $data = $request->validate(['text' => ['required', 'string', 'min:3', 'max:500'], 'lang' => ['nullable', 'in:en,th']]);
        $lang = $data['lang'] ?? 'en';
        $text = trim(preg_replace('/\s+/u', ' ', $data['text']));
        $log = ['api_client_id' => $client->id, 'endpoint' => 'ai_parse', 'reference' => mb_substr($text, 0, 50), 'ip' => $request->ip(), 'end_user_ip' => $ip];

        if (! $this->openAi->isEnabled()) {
            return $this->fail($log, 503, 'ai_disabled');
        }

        $cacheKey = 'public-ai-parse:'.$lang.':'.md5(mb_strtolower($text));
        if (($cached = Cache::get($cacheKey)) !== null) {
            $this->log($log + ['status_code' => 200, 'cached' => true]);

            return response()->json($cached);
        }

        foreach ([
            ["ai-parse:ip-min:{$ip}", self::PER_IP_PER_MINUTE, 60],
            ["ai-parse:ip-day:{$ip}", self::PER_IP_PER_DAY, 86400],
            ["ai-parse:site-day:{$client->id}", self::PER_SITE_PER_DAY, 86400],
        ] as [$bucket, $limit, $decay]) {
            if (! RateLimiter::attempt($bucket, $limit, fn () => true, $decay)) {
                return $this->fail($log, 429, 'rate_limited');
            }
        }

        try {
            $raw = $this->openAi->chatJson(self::PROMPT, $text."

(Write \"note\" in ".($lang === 'th' ? 'Thai' : 'English').'.)');
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($log, 502, 'ai_unavailable', $e->getMessage());
        }

        $result = $this->sanitize($raw);
        Cache::put($cacheKey, $result, now()->addDay());
        $this->log($log + ['status_code' => 200, 'result_count' => count($result['packages']), 'destination_country' => $result['destination']['country']]);

        return response()->json($result);
    }

    /** Clamp the model's answer to what the form accepts — it is never trusted as-is. */
    private function sanitize(array $raw): array
    {
        $num = fn ($v, float $min, float $max) => is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : null;
        $country = strtoupper((string) ($raw['destination_country_iso2'] ?? ''));
        $country = preg_match('/^[A-Z]{2}$/', $country) && $country !== 'TH' ? $country : null;
        $isDocument = ($raw['shipment_type'] ?? '') === 'document';

        $packages = [];
        foreach (array_slice(is_array($raw['packages'] ?? null) ? $raw['packages'] : [], 0, 20) as $p) {
            $weight = $num($p['weight_kg'] ?? null, 0.1, 300);
            if ($weight === null) {
                continue;
            }
            $packages[] = [
                'weight' => $weight,
                'length' => $isDocument ? null : $num($p['length_cm'] ?? null, 1, 300),
                'width' => $isDocument ? null : $num($p['width_cm'] ?? null, 1, 300),
                'height' => $isDocument ? null : $num($p['height_cm'] ?? null, 1, 300),
                'quantity' => (int) max(1, min(50, (int) ($p['quantity'] ?? 1))),
                'estimated' => (bool) ($p['estimated'] ?? false),
                'item' => isset($p['item']) && is_string($p['item']) ? mb_substr(strip_tags($p['item']), 0, 80) : null,
            ];
        }

        return [
            'understood' => (bool) ($raw['understood'] ?? ($country || $packages)),
            'destination' => [
                'country' => $country,
                'city' => isset($raw['destination_city']) && is_string($raw['destination_city']) ? mb_substr(strip_tags($raw['destination_city']), 0, 100) : null,
                'postcode' => isset($raw['destination_postal_code']) && is_scalar($raw['destination_postal_code']) ? mb_substr((string) $raw['destination_postal_code'], 0, 20) : null,
            ],
            'shipment_type' => $isDocument ? 'document' : 'parcel',
            'packages' => $packages,
            'note' => isset($raw['note']) && is_string($raw['note']) ? mb_substr(strip_tags($raw['note']), 0, 240) : null,
        ];
    }

    private function fail(array $log, int $status, string $code, ?string $detail = null)
    {
        $this->log($log + ['status_code' => $status, 'error' => mb_substr($detail ?? $code, 0, 500)]);

        return response()->json(['error' => ['code' => $code]], $status);
    }

    private function log(array $attributes): void
    {
        try {
            ApiRequestLog::create($attributes);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
