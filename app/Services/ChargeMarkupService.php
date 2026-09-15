<?php

namespace App\Services;

use App\Models\ChargeFixedOverride;
use App\Models\MarkupRule;

class ChargeMarkupService
{
    /**
     * These codes carry the carrier's own real cost (used only as a cost reference — see
     * ShippingController/shipment UI) and must never be fixed-overridden or marked up here.
     */
    private const COST_ONLY_CODES = ['400', 'II'];

    /**
     * Apply each account's configured fixed charge overrides (ChargeFixedOverride — intercepts
     * the carrier's raw amount first) and MarkupRule (value/unit markup on top of that) to every
     * chargeBreakdown line of every quote, then adjust published/negotiated totals by the same
     * net delta so the total always still matches the sum of its breakdown lines.
     */
    public function applyToResults(array $results): array
    {
        $accountIds = collect($results)->pluck('accountId')->filter()->unique()->values()->all();
        if (empty($accountIds)) {
            return $results;
        }

        $overridesByAccount = ChargeFixedOverride::with('chargeCode')
            ->whereIn('agent_account_id', $accountIds)
            ->where('status', true)
            ->get()
            ->filter(fn ($override) => $override->chargeCode !== null)
            ->groupBy('agent_account_id')
            ->map(fn ($overrides) => $overrides->keyBy(fn ($override) => $override->chargeCode->code));

        $rulesByAccount = MarkupRule::with('chargeCode')
            ->whereIn('agent_account_id', $accountIds)
            ->where('status', true)
            ->get()
            ->filter(fn ($rule) => $rule->chargeCode !== null)
            ->groupBy('agent_account_id')
            ->map(fn ($rules) => $rules->keyBy(fn ($rule) => $rule->chargeCode->code));

        return array_map(function ($result) use ($overridesByAccount, $rulesByAccount) {
            if (empty($result['chargeBreakdown']) || empty($result['accountId'])) {
                return $result;
            }

            $accountOverrides = $overridesByAccount->get($result['accountId']);
            $accountRules = $rulesByAccount->get($result['accountId']);
            if ((! $accountOverrides || $accountOverrides->isEmpty()) && (! $accountRules || $accountRules->isEmpty())) {
                return $result;
            }

            $delta = 0.0;
            $result['chargeBreakdown'] = array_map(function ($line) use ($accountOverrides, $accountRules, &$delta) {
                $code = $line['code'] ?? null;
                if ($code === null || in_array($code, self::COST_ONLY_CODES, true)) {
                    return $line;
                }

                $override = $accountOverrides?->get($code);
                $rule = $accountRules?->get($code);
                if (! $override && ! $rule) {
                    return $line;
                }

                $original = (float) $line['amount'];
                $base = $override ? (float) $override->fixed_amount : $original;
                $new = $rule
                    ? ($rule->unit === 'PERCENTAGE' ? $base * (1 + (float) $rule->value / 100) : $base + (float) $rule->value)
                    : $base;
                $new = round($new, 2);

                $delta += $new - $original;
                $line['amount'] = $new;

                return $line;
            }, $result['chargeBreakdown']);

            if ($delta !== 0.0) {
                if (isset($result['published']) && $result['published'] !== null) {
                    $result['published'] = round($result['published'] + $delta, 2);
                }
                if (isset($result['negotiated']) && $result['negotiated'] !== null) {
                    $result['negotiated'] = round($result['negotiated'] + $delta, 2);
                }
            }

            return $result;
        }, $results);
    }
}
