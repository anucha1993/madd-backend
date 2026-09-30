<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public Rate API auth: `Authorization: Bearer <key>` (or `X-Api-Key`) of an active ApiClient,
 * optionally only from its allowed server IPs, rate-limited per key and per end customer
 * (`X-End-User-IP`, forwarded by the calling site — falls back to the caller's own IP).
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken() ?: $request->header('X-Api-Key');
        $client = $key ? ApiClient::findByKey($key) : null;
        if (! $client || ! $client->status) {
            return $this->error('invalid_api_key', 'API key ไม่ถูกต้องหรือถูกปิดใช้งาน', 401);
        }
        if ($client->allowed_ips && ! IpUtils::checkIp((string) $request->ip(), $client->allowed_ips)) {
            return $this->error('ip_not_allowed', 'IP นี้ไม่ได้รับอนุญาตให้ใช้ API key นี้', 403);
        }

        $endUserIp = filter_var($request->header('X-End-User-IP'), FILTER_VALIDATE_IP) ?: $request->ip();
        foreach ([["api-client:{$client->id}", $client->rate_limit_per_minute], ["api-client:{$client->id}:ip:{$endUserIp}", $client->end_user_limit_per_minute]] as [$bucket, $limit]) {
            if ($limit > 0 && ! RateLimiter::attempt($bucket, $limit, fn () => true, 60)) {
                return $this->error('rate_limited', 'เรียกใช้บ่อยเกินไป กรุณาลองใหม่ในอีกสักครู่', 429, ['Retry-After' => (string) RateLimiter::availableIn($bucket)]);
            }
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_client', $client);
        $request->attributes->set('end_user_ip', $endUserIp);

        return $next($request);
    }

    private function error(string $code, string $message, int $status, array $headers = []): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
