<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side Public API (`api.web:tracking` / `api.web:rates`): identified by the page's
 * Origin (listed in an active client's `browser_origins`) instead of an API key, since a key
 * can't be kept secret in a web page. Only tracking (no personal data) and sell-price quotes
 * are exposed this way, the client must allow that feature, and each visitor is rate-limited by
 * their own IP. Answers carry CORS headers for that Origin only.
 */
class AuthenticateWebOrigin
{
    public function handle(Request $request, Closure $next, string $feature = 'tracking'): Response
    {
        $origin = self::normalize((string) $request->headers->get('Origin'));
        // 'view' (page-view beacon) only needs the site to be registered for either feature.
        $client = $origin ? ApiClient::where('status', true)->whereNotNull('browser_origins')
            ->where(fn ($q) => $feature === 'view' ? $q->where('allow_rates', true)->orWhere('allow_tracking', true) : $q->where($feature === 'rates' ? 'allow_rates' : 'allow_tracking', true))
            ->get()
            ->first(fn (ApiClient $c) => in_array($origin, array_map([self::class, 'normalize'], $c->browser_origins ?? []), true)) : null;

        if (! $client) {
            return response()->json(['error' => ['code' => 'origin_not_allowed', 'message' => 'เว็บไซต์นี้ยังไม่ได้รับอนุญาตให้ใช้ API นี้จาก Browser']], 403);
        }

        $ip = (string) $request->ip();
        // The country list is static reference data — not worth a visitor's request budget.
        $limits = $request->is('api/public/v1/web/countries', 'api/public/v1/web/hit') ? [] : [["web-client:{$client->id}", $client->rate_limit_per_minute], ["web-client:{$client->id}:ip:{$ip}", $client->end_user_limit_per_minute]];
        foreach ($limits as [$bucket, $limit]) {
            if ($limit > 0 && ! RateLimiter::attempt($bucket, $limit, fn () => true, 60)) {
                return $this->cors(response()->json(['error' => ['code' => 'rate_limited', 'message' => 'เรียกใช้บ่อยเกินไป กรุณาลองใหม่ในอีกสักครู่']], 429, ['Retry-After' => (string) RateLimiter::availableIn($bucket)]), $origin);
            }
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_client', $client);
        $request->attributes->set('end_user_ip', $ip);

        return $this->cors($next($request), $origin);
    }

    /** "https://www.Example.com/" → "https://www.example.com" */
    public static function normalize(string $origin): string
    {
        $parts = parse_url(trim($origin));
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function cors(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
