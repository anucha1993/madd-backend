<?php

namespace App\Services;

use App\Models\Country;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

class RestCountriesService
{
    private const SETTING_KEY = 'restcountries_api_key';

    /** DB-stored key (managed via the Countries settings UI) takes priority over the
     * .env default, so the key can be rotated without a deploy/config change. */
    public function getApiKey(): ?string
    {
        return IntegrationSetting::get(self::SETTING_KEY) ?: config('services.restcountries.api_key');
    }

    public function setApiKey(?string $apiKey): void
    {
        IntegrationSetting::set(self::SETTING_KEY, $apiKey);
    }

    public function isConfigured(): bool
    {
        return (bool) $this->getApiKey();
    }

    public function maskedApiKey(): ?string
    {
        $key = $this->getApiKey();
        if (! $key) {
            return null;
        }

        return strlen($key) <= 8 ? str_repeat('•', strlen($key)) : substr($key, 0, 7) . str_repeat('•', 8) . substr($key, -4);
    }

    /** Fetches every country from restcountries.com (paginated, 100 per page on the
     * free plan) and upserts them into our local `countries` table by ISO2 code. */
    public function sync(): int
    {
        $apiKey = $this->getApiKey();
        if (! $apiKey) {
            throw new \RuntimeException('RESTCOUNTRIES_API_KEY is not configured.');
        }

        $baseUrl = config('services.restcountries.base_url');
        $limit = 100;
        $offset = 0;
        $total = null;
        $synced = 0;
        $now = now();

        do {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->get($baseUrl, [
                    'limit' => $limit,
                    'offset' => $offset,
                    'response_fields' => 'names.common,codes.alpha_2,region,subregion',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('REST Countries request failed: ' . ($response->json('errors.0.message') ?? $response->status()));
            }

            $objects = $response->json('data.objects', []);
            $total = $response->json('data.meta.total', 0);

            foreach ($objects as $obj) {
                $iso2 = $obj['codes']['alpha_2'] ?? null;
                $name = $obj['names']['common'] ?? null;
                if (! $iso2 || ! $name) {
                    continue;
                }

                Country::updateOrCreate(
                    ['iso2' => strtoupper($iso2)],
                    [
                        'name' => $name,
                        'region' => $obj['region'] ?? null,
                        'subregion' => $obj['subregion'] ?? null,
                        'synced_at' => $now,
                    ]
                );
                $synced++;
            }

            $offset += $limit;
        } while ($offset < $total);

        return $synced;
    }
}
