<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Country;
use App\Services\PublicRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/public/v1/rates — estimated sell prices for an external site (see
 * AuthenticateApiClient). Identical requests are answered from cache for 15 minutes so a busy
 * website doesn't burn the carriers' rate-API quota.
 */
class RateController extends Controller
{
    private const CACHE_MINUTES = 15;

    public const DISCLAIMER = 'ราคาประมาณการจากต้นทางมาตรฐาน อาจเปลี่ยนแปลงตามที่อยู่รับของ น้ำหนักและขนาดจริง';

    public function __construct(private PublicRateService $rates) {}

    public function rates(Request $request)
    {
        $startedAt = microtime(true);
        /** @var ApiClient $client */
        $client = $request->attributes->get('api_client');
        $log = ['api_client_id' => $client->id, 'ip' => $request->ip(), 'end_user_ip' => $request->attributes->get('end_user_ip')];

        try {
            $input = $request->validate([
                'destination' => ['required', 'array'],
                'destination.country' => ['required', 'string', 'size:2', 'not_in:TH,th'],
                'destination.city' => ['nullable', 'string', 'max:100'],
                'destination.postcode' => ['nullable', 'string', 'max:20'],
                'origin' => ['nullable', 'array'],
                'origin.postcode' => ['nullable', 'string', 'regex:/^\d{5}$/'],
                'origin.city' => ['nullable', 'string', 'max:100'],
                'shipment_type' => ['required', 'in:document,parcel'],
                'packages' => ['required', 'array', 'min:1', 'max:20'],
                'packages.*.weight' => ['required', 'numeric', 'min:0.1', 'max:300'],
                'packages.*.length' => ['nullable', 'numeric', 'min:1', 'max:300'],
                'packages.*.width' => ['nullable', 'numeric', 'min:1', 'max:300'],
                'packages.*.height' => ['nullable', 'numeric', 'min:1', 'max:300'],
                'packages.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
        } catch (ValidationException $e) {
            $this->log($log + ['status_code' => 422, 'error' => mb_substr(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 0, 500)], $startedAt);

            return response()->json(['error' => ['code' => 'invalid_request', 'message' => 'ข้อมูลไม่ถูกต้อง', 'fields' => $e->errors()]], 422);
        }
        $input['destination']['country'] = strtoupper($input['destination']['country']);

        $log += [
            'destination_country' => $input['destination']['country'],
            'total_weight' => collect($input['packages'])->sum(fn ($p) => (float) $p['weight'] * max(1, (int) ($p['quantity'] ?? 1))),
            'pieces' => collect($input['packages'])->sum(fn ($p) => max(1, (int) ($p['quantity'] ?? 1))),
        ];

        $cacheKey = 'public-rates:'.$client->id.':'.$client->updated_at?->timestamp.':'.md5(json_encode($input));
        $options = Cache::get($cacheKey);
        $cached = $options !== null;
        try {
            if (! $cached) {
                $options = $this->rates->quote($client, $input);
                // An empty answer is usually a carrier outage — don't pin it for 15 minutes.
                if ($options) {
                    Cache::put($cacheKey, $options, now()->addMinutes(self::CACHE_MINUTES));
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $this->log($log + ['status_code' => 502, 'error' => mb_substr($e->getMessage(), 0, 500)], $startedAt);

            return response()->json(['error' => ['code' => 'carrier_unavailable', 'message' => 'ไม่สามารถเช็คราคาได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง']], 502);
        }

        $requestId = $this->log($log + [
            'status_code' => 200,
            'cached' => $cached,
            'result_count' => count($options),
            'lowest_price' => $options[0]['price'] ?? null,
        ], $startedAt);

        return response()->json([
            'request_id' => $requestId,
            'destination' => $input['destination'],
            'shipment_type' => $input['shipment_type'],
            'options' => $options,
            'disclaimer' => self::DISCLAIMER,
        ]);
    }

    /** GET /api/public/v1/countries — active destination countries for the site's dropdown. */
    public function countries()
    {
        return response()->json(['countries' => Country::where('status', true)->where('iso2', '!=', 'TH')->orderBy('name')->get(['iso2', 'name'])]);
    }

    private function log(array $attributes, float $startedAt): ?int
    {
        try {
            return ApiRequestLog::create($attributes + ['duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)])->id;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
