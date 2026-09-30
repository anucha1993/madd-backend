<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Services\PublicTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/public/v1/tracking/{trackingNumber} — status + scan history of a shipment booked in
 * MADD (see PublicTrackingService). Answers are cached 10 minutes per tracking number so a
 * customer refreshing the page doesn't hit the carrier each time; unknown numbers get the same
 * 404 whether they exist at the carrier or not.
 */
class TrackingController extends Controller
{
    private const CACHE_MINUTES = 10;

    public function __construct(private PublicTrackingService $tracking) {}

    public function show(Request $request, string $trackingNumber)
    {
        $startedAt = microtime(true);
        /** @var ApiClient $client */
        $client = $request->attributes->get('api_client');
        $trackingNumber = strtoupper(preg_replace('/\s+/', '', $trackingNumber));
        $log = ['api_client_id' => $client->id, 'endpoint' => $request->is('api/public/v1/web/*') ? 'web_tracking' : 'tracking', 'reference' => mb_substr($trackingNumber, 0, 50), 'ip' => $request->ip(), 'end_user_ip' => $request->attributes->get('end_user_ip')];

        if (! $client->allow_tracking) {
            return $this->fail($log, $startedAt, 403, 'endpoint_not_allowed', 'API key นี้ไม่ได้เปิดใช้การติดตามพัสดุ');
        }
        if (! preg_match('/^[A-Z0-9]{8,40}$/', $trackingNumber)) {
            return $this->fail($log, $startedAt, 422, 'invalid_request', 'รูปแบบเลข Tracking ไม่ถูกต้อง');
        }

        $shipment = $this->tracking->find($trackingNumber);
        if (! $shipment) {
            return $this->fail($log, $startedAt, 404, 'not_found', 'ไม่พบเลข Tracking นี้ในระบบ');
        }

        $cacheKey = 'public-tracking:'.$trackingNumber.':'.$shipment->status;
        $data = Cache::get($cacheKey);
        $cached = $data !== null;
        if (! $cached) {
            try {
                $data = $this->tracking->track($shipment, $trackingNumber);
                Cache::put($cacheKey, $data, now()->addMinutes(self::CACHE_MINUTES));
            } catch (\Throwable $e) {
                report($e);

                return $this->fail($log, $startedAt, 502, 'carrier_unavailable', 'ไม่สามารถดึงข้อมูลจาก Carrier ได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง', $e->getMessage());
            }
        }

        $this->log($log + ['status_code' => 200, 'cached' => $cached, 'result_count' => count($data['events']), 'destination_country' => $data['destination_country']], $startedAt);

        return response()->json($data);
    }

    private function fail(array $log, float $startedAt, int $status, string $code, string $message, ?string $detail = null)
    {
        $this->log($log + ['status_code' => $status, 'error' => mb_substr($detail ?? $code, 0, 500)], $startedAt);

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
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
