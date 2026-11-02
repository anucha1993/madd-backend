<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * AI + OCR through DataCorp (OPENAI_BASE_URL=https://datacorpapi.com/v1, the OpenAI key in Integrations = a dca_sk_ key):
 * plan, credits and payment / upgrade links of that key. The key never leaves this server.
 */
class DataCorpBillingService
{
    private const CACHE_KEY = 'datacorp.ai.status';

    public function __construct(private OpenAiService $openAi)
    {
    }

    public function enabled(): bool
    {
        $flag = config('services.openai.datacorp');
        $on = $flag !== null && $flag !== ''
            ? filter_var($flag, FILTER_VALIDATE_BOOLEAN)
            : str_contains((string) config('services.openai.base_url'), 'datacorpapi.com');

        return $on && $this->openAi->isConfigured();
    }

    /** https://datacorpapi.com/v1 */
    public function baseUrl(): string
    {
        return rtrim((string) config('services.openai.base_url'), '/');
    }

    public function apiKey(): ?string
    {
        return $this->openAi->getApiKey();
    }

    /**
     * ['plan','used','included','remaining','renews_at','blocked','reason','message','upgrade_url','upgrade_plan',
     *  'upgrade_price','pay_url','days_left','renew_due','auto_renew'] — null when not using DataCorp or unreachable.
     */
    public function status(bool $fresh = false): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        if ($fresh) {
            $this->forget();
        }

        return Cache::remember(self::CACHE_KEY, 60, function () {
            try {
                $r = Http::withToken($this->apiKey())->acceptJson()->timeout(15)
                    ->get(preg_replace('#/v1$#', '', $this->baseUrl()).'/api/v1/usage');
            } catch (\Throwable $e) {
                return null;
            }
            $j = $r->json() ?: [];
            $b = $j['billing'] ?? [];
            $q = $j['quota'] ?? [];

            return [
                'plan' => $j['plan'] ?? null,
                'used' => (int) ($q['used'] ?? 0),
                'included' => isset($q['included']) ? (int) $q['included'] : null,
                'remaining' => isset($q['remaining']) ? (int) $q['remaining'] : null,
                'renews_at' => $j['renews_at'] ?? null,
                'blocked' => ! ($j['ok'] ?? false),
                'reason' => $j['error'] ?? null,
                'message' => isset($j['message']) ? explode(' — ', $j['message'])[0] : null,
                'upgrade_url' => $b['upgrade_url'] ?? null,
                'upgrade_plan' => $b['upgrade_plan'] ?? null,
                'upgrade_price' => $b['upgrade_price_thb'] ?? null,
                'pay_url' => $j['action_url'] ?? ($b['checkout_url'] ?? ($b['pay_url'] ?? null)),
                // fallback when DataCorp gives no direct upgrade link: the plans page of the DataCorp portal (needs login)
                'plans_url' => $b['plans_url'] ?? (preg_replace('#/v1$#', '', $this->baseUrl()).'/portal/plans'),
                // trial / expired: one pay button per paid plan [{name, price_thb, billing, included, recommended, url}]
                'plans' => array_values(array_filter($b['plans'] ?? [], fn ($p) => ! empty($p['url']))),
                'days_left' => $b['days_left'] ?? null,
                'renew_due' => (bool) ($b['renew_due'] ?? false),
                'auto_renew' => (bool) ($b['auto_renew'] ?? false),
            ];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
