<?php

namespace App\Services;

use App\Models\ChargeFixedOverride;
use App\Models\MarkupRule;

class ChargeMarkupService
{
    /**
     * These codes carry the carrier's own real cost (used only as a cost reference — see
     * ShippingController/shipment UI) and must never be fixed-overridden or marked up here.
     * 'IB' is DHL's document-only Extended Liability service (see DhlRateService).
     */
    private const COST_ONLY_CODES = ['400', 'II', 'IB'];

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
            if (! empty($result['error']) || empty($result['accountId'])) {
                return $result;
            }

            $accountOverrides = $overridesByAccount->get($result['accountId']);
            $accountRules = $rulesByAccount->get($result['accountId']);
            if ((! $accountOverrides || $accountOverrides->isEmpty()) && (! $accountRules || $accountRules->isEmpty())) {
                return $result;
            }

            $breakdown = $result['chargeBreakdown'] ?? [];

            $delta = 0.0;
            // Tracked separately from $delta: only the portion coming from a MarkupRule (not
            // from a ChargeFixedOverride) — this is what the frontend shows as "(+X Marked Up)".
            $markupTotal = 0.0;
            $breakdown = array_map(function ($line) use ($accountOverrides, $accountRules, &$delta, &$markupTotal) {
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
                if ($rule) {
                    $markupTotal += round($new - $base, 2);
                    // Lets the frontend show e.g. "7% × 1,200.00" instead of just the final amount.
                    $line['markupUnit'] = $rule->unit;
                    $line['markupValue'] = (float) $rule->value;
                    $line['markupBase'] = round($base, 2);
                }
                $line['amount'] = $new;

                return $line;
            }, $breakdown);

            // MarkupRule entries whose charge code the carrier never returned (e.g. a self-defined
            // "VAT" charge code created via /config/markup "Add Charge Code") — inject them as
            // brand new sell-only lines instead of silently discarding the rule, so staff can add
            // charges that have nothing to do with the carrier's own chargeBreakdown.
            if ($accountRules) {
                // PHP casts purely-numeric string array/Collection keys (e.g. "375") to int — cast
                // back to string on both sides so this comparison isn't silently always-false for
                // UPS's numeric charge codes, which would otherwise inject a duplicate line below.
                $existingCodes = collect($breakdown)->pluck('code')->filter()->map(fn ($code) => (string) $code)->all();
                $sellSubtotal = collect($breakdown)
                    ->filter(fn ($line) => ! in_array($line['code'] ?? null, self::COST_ONLY_CODES, true))
                    ->sum('amount');
                $currency = $breakdown[0]['currency'] ?? ($result['currency'] ?? 'THB');

                foreach ($accountRules as $code => $rule) {
                    if (in_array((string) $code, $existingCodes, true)) {
                        continue;
                    }

                    $extra = $rule->unit === 'PERCENTAGE'
                        ? round($sellSubtotal * ((float) $rule->value / 100), 2)
                        : round((float) $rule->value, 2);

                    if ($extra === 0.0) {
                        continue;
                    }

                    $breakdown[] = [
                        'code' => (string) $code,
                        'description' => $rule->chargeCode->label,
                        'amount' => $extra,
                        'currency' => $currency,
                        'isCustomCharge' => true,
                        'markupUnit' => $rule->unit,
                        'markupValue' => (float) $rule->value,
                        // PERCENTAGE is computed off this quote's own sell subtotal; BAHT is a flat
                        // add with no base amount to show.
                        'markupBase' => $rule->unit === 'PERCENTAGE' ? round($sellSubtotal, 2) : null,
                    ];

                    $delta += $extra;
                    $markupTotal += $extra;
                }
            }

            $result['chargeBreakdown'] = $breakdown;

            if ($delta !== 0.0) {
                if (isset($result['published']) && $result['published'] !== null) {
                    $result['published'] = round($result['published'] + $delta, 2);
                }
                if (isset($result['negotiated']) && $result['negotiated'] !== null) {
                    $result['negotiated'] = round($result['negotiated'] + $delta, 2);
                }
            }

            if ($markupTotal !== 0.0) {
                $result['markupTotal'] = round($markupTotal, 2);
            }

            return $result;
        }, $results);
    }
}
