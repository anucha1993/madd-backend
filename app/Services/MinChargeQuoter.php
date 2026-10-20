<?php

namespace App\Services;

use App\Models\AgentAccount;
use App\Support\WholeKgBilledWeight;

/**
 * "Minimum charge" services: a service whose Check Rate range (/config/agent-accounts) starts above
 * the shipment's weight but has a min_charge (e.g. Expedited 25.01–70, min charge 25 kg) is still
 * sold — priced as if the shipment weighed min_charge. The carrier is asked again for that service
 * with one box of that weight; the booking itself still sends the real packages.
 */
class MinChargeQuoter
{
    public function __construct(private UpsRateService $ups, private DhlRateService $dhl)
    {
    }

    /**
     * Swaps each such service's real-weight quote for its min-charge quote (marked
     * `minChargeWeight`). $allowedServiceCodes = per-account branch restrictions (null = all).
     *
     * @param  iterable<AgentAccount>  $accounts
     */
    public function apply(array $results, iterable $accounts, array $shipment, float $billedWeight, array $allowedServiceCodes = []): array
    {
        foreach ($accounts as $account) {
            $carrier = $account->agent?->agent_code;
            foreach ($account->serviceRanges() as $range) {
                $code = $range['service_code'];
                $minCharge = $account->minChargeFor($code, $billedWeight);
                $allowed = $allowedServiceCodes[$account->id] ?? null;
                if ($minCharge === null || ! in_array($carrier, ['UPS', 'DHL'], true) || ($allowed !== null && ! in_array($code, $allowed, true))) {
                    continue;
                }
                $quote = $this->quoteAt($account, $carrier, $code, $shipment, $minCharge);
                if ($quote === null) {
                    continue; // carrier didn't price it — keep whatever the real-weight quote said
                }
                $results = array_values(array_filter($results, fn ($r) => ! (($r['accountId'] ?? null) === $account->id && strcasecmp((string) ($r['serviceCode'] ?? ''), $code) === 0)));
                $results[] = $quote + ['minChargeWeight' => $minCharge];
            }
        }

        return $results;
    }

    private function quoteAt(AgentAccount $account, string $carrier, string $code, array $shipment, float $weight): ?array
    {
        $first = $shipment['packages'][0] ?? [];
        $declared = array_sum(array_map(fn ($p) => (float) ($p['declaredValue'] ?? 0), $shipment['packages']));
        // One small box of the minimum weight (its actual weight decides the price).
        $packages = WholeKgBilledWeight::forQuote([[
            'weight' => $weight, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1,
            'isDocument' => (bool) ($first['isDocument'] ?? false),
            'declaredValue' => $declared > 0 ? $declared : null,
        ]]);
        $atMin = ['packages' => $packages] + $shipment;

        try {
            if ($carrier === 'UPS') {
                $results = $this->ups->quoteAccounts([[
                    'id' => $account->id, 'username_acc' => $account->username_acc, 'client_id' => $account->client_id,
                    'client_secret' => $account->client_secret, 'mode' => $account->mode,
                ]], $atMin, [$code]);
            } else {
                $results = $this->dhl->quoteAccounts([[
                    'id' => $account->id, 'username_acc' => $account->username_acc, 'basic_auth_username' => $account->basic_auth_username,
                    'basic_auth_password' => $account->basic_auth_password, 'mode' => $account->mode,
                ]], $atMin);
            }
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return collect($results)->first(fn ($r) => empty($r['error']) && strcasecmp((string) ($r['serviceCode'] ?? ''), $code) === 0);
    }
}
