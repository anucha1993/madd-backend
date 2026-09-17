<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * UPS reduced its OAuth access token lifetime from 4 hours to 1 hour in Q3 2026 (per UPS
 * Support) — cache for 55 minutes (safely under that) rather than re-authenticating on every
 * single API call, with `$forceRefresh` for callers that hit a 401 mid-cache-window (see
 * UpsTokenExpiredException) and need a brand new token immediately.
 */
trait HasUpsOAuthToken
{
    // Implemented by the consuming service (test vs production host per account `mode`).
    abstract private function upsUrl(string $key, ?string $mode): string;

    public function getAccessToken(string $clientId, string $clientSecret, ?string $mode = null, bool $forceRefresh = false): string
    {
        $cacheKey = 'ups_oauth_token:'.md5($clientId.'|'.($mode ?? ''));

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3300, function () use ($clientId, $clientSecret, $mode) {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->timeout(15)
                ->post($this->upsUrl('oauth_url', $mode), ['grant_type' => 'client_credentials']);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new \RuntimeException('UPS OAuth failed: '.($response->json('error_description') ?? $response->status()));
            }

            return $response->json('access_token');
        });
    }
}
