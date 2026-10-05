<?php

namespace App\Services;

use App\Models\ChargeFixedOverride;
use App\Models\MarkupRule;
use App\Models\ZonePrice;

class ChargeMarkupService
{
    /**
     * These codes carry the carrier's own real cost (used only as a cost reference — see
     * ShippingController/shipment UI) and must never be fixed-overridden or marked up here.
     * 'IB' is DHL's document-only Extended Liability service (see DhlRateService). Public so
     * other consumers of a shipment's `rate_quote.chargeBreakdown` (e.g. ReceiptController's
     * suggested line items) can also exclude these from a real customer-facing sell total —
     * their actual sell price is billed separately via the Insurance Add-on line instead.
     */
    public const COST_ONLY_CODES = ['400', 'II', 'IB'];

    /**
     * Apply each account's configured fixed charge overrides (ChargeFixedOverride — intercepts
     * the carrier's raw amount first) and MarkupRule (value/unit markup on top of that) to every
     * chargeBreakdown line of every quote, then adjust published/negotiated totals by the same
     * net delta so the total always still matches the sum of its breakdown lines.
     *
     * @param  array<int, array{weight?: mixed, quantity?: mixed}>  $packages  the rate request's
     *         packages — feeds {BOX} and BOX_OVER(kg) in formulas (see ChargeFormulaEvaluator)
     * @param  ?string  $destinationCountry  ISO2 — feeds {ZONE} / {ZONE_PRICE} (staff's own zone
     *         prices, /config/countries) for the carrier of each quote
     */
    public function applyToResults(array $results, array $packages = [], ?string $destinationCountry = null): array
    {
        // One weight per physical box (a package line with quantity 3 = 3 boxes).
        $packageWeights = [];
        foreach ($packages as $package) {
            for ($i = 0; $i < max(1, (int) ($package['quantity'] ?? 1)); $i++) {
                $packageWeights[] = (float) ($package['weight'] ?? 0);
            }
        }
        $formulaContext = ['package_weights' => $packageWeights];

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

        // Manual zone + prices per carrier for this destination, looked up once per carrier.
        $zoneLookups = [];

        return array_map(function ($result) use ($overridesByAccount, $rulesByAccount, $formulaContext, $packageWeights, $destinationCountry, &$zoneLookups) {
            if (! empty($result['error']) || empty($result['accountId'])) {
                return $result;
            }

            // Snapshot the carrier's own totals/breakdown BEFORE any override/markup below
            // touches them — this is the only trustworthy "real cost" reference (see
            // Shipment::cost_amount); published/negotiated/chargeBreakdown further down get
            // mutated into the customer-facing SELL price and must never be read back as cost.
            $result['costPublished'] = $result['published'] ?? null;
            $result['costNegotiated'] = $result['negotiated'] ?? null;
            $result['costBreakdown'] = $result['chargeBreakdown'] ?? [];

            $accountOverrides = $overridesByAccount->get($result['accountId']);
            $accountRules = $rulesByAccount->get($result['accountId']);
            if ((! $accountOverrides || $accountOverrides->isEmpty()) && (! $accountRules || $accountRules->isEmpty())) {
                return $result;
            }

            $breakdown = $result['chargeBreakdown'] ?? [];

            // FORMULA overrides reference OTHER charge codes' amounts (see ChargeFormulaEvaluator)
            // — always the carrier's own ORIGINAL amounts, captured before any override/markup below
            // mutates $breakdown, so a formula's result never depends on processing order.
            $rawAmountsByCode = [];
            foreach ($breakdown as $line) {
                if (isset($line['code'])) {
                    $rawAmountsByCode[(string) $line['code']] = (float) ($line['amount'] ?? 0);
                }
            }
            // Special formula variables (see ChargeFormulaEvaluator/frontend
            // chargeFormulaVariables.ts) — resolved from the quote itself, not from any
            // chargeBreakdown line, so a formula can reference e.g. {TOTAL} * 2% just like a
            // real charge code.
            $rawAmountsByCode['BILLED_WEIGHT'] = (float) ($result['billedWeight'] ?? 0);
            $rawAmountsByCode['TOTAL'] = (float) ($result['negotiated'] ?? $result['published'] ?? $result['total'] ?? 0);
            $rawAmountsByCode['W'] = $rawAmountsByCode['BILLED_WEIGHT']; // short alias for IF formulas
            $rawAmountsByCode['BOX'] = (float) count($packageWeights);

            $carrier = strtoupper((string) ($result['carrier'] ?? ''));
            $zoneLookups[$carrier] ??= ZonePrice::lookup($carrier, $destinationCountry);
            $zoneInfo = $zoneLookups[$carrier];
            if ($zoneInfo['zone'] !== null && is_numeric($zoneInfo['zone'])) {
                $rawAmountsByCode['ZONE'] = (float) $zoneInfo['zone'];
            }
            // {ZONE_PRICE} = the manual price for the charge code being computed. Left OUT (not 0)
            // when nothing is configured for this destination, so the formula fails and the line
            // falls back to the carrier's own API amount — "manual if set, otherwise API".
            $valuesFor = function ($code) use ($rawAmountsByCode, $zoneInfo) {
                $price = $zoneInfo['prices'][(string) $code] ?? null;

                return $price === null ? $rawAmountsByCode : $rawAmountsByCode + ['ZONE_PRICE' => $price];
            };

            $delta = 0.0;
            // Tracked separately from $delta: only the portion coming from a MarkupRule (not
            // from a ChargeFixedOverride) — this is what the frontend shows as "(+X Marked Up)".
            $markupTotal = 0.0;
            $breakdown = array_map(function ($line) use ($accountOverrides, $accountRules, $valuesFor, $formulaContext, &$delta, &$markupTotal) {
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
                $base = $original;
                $values = $valuesFor($code);
                if ($override) {
                    if ($override->override_type === 'FORMULA' && $override->formula) {
                        try {
                            $base = ChargeFormulaEvaluator::evaluate($override->formula, $values, $formulaContext);
                        } catch (\Throwable $e) {
                            // Bad/unresolvable formula (e.g. references a code this quote doesn't
                            // have) — fall back to the carrier's own amount instead of breaking
                            // the whole rate quote over one misconfigured override.
                            $base = $original;
                        }
                    } else {
                        // PERCENTAGE replaces the amount with that % of the carrier's own
                        // original quote for this same line; THB is a flat replacement value.
                        $base = $override->unit === 'PERCENTAGE'
                            ? $original * ((float) $override->fixed_amount / 100)
                            : (float) $override->fixed_amount;
                    }
                }
                $new = $rule
                    ? ($rule->rule_type === 'FORMULA' && $rule->formula
                        ? $this->safeEvaluateFormula($rule->formula, $values, $base, $formulaContext)
                        : ($rule->unit === 'PERCENTAGE' ? $base * (1 + (float) $rule->value / 100) : $base + (float) $rule->value))
                    : $base;
                $new = round($new, 2);

                $delta += $new - $original;
                if ($rule) {
                    $markupTotal += round($new - $base, 2);
                    // Lets the frontend show e.g. "7% × 1,200.00" instead of just the final amount.
                    $line['markupUnit'] = $rule->rule_type === 'FORMULA' ? 'FORMULA' : $rule->unit;
                    $line['markupValue'] = $rule->rule_type === 'FORMULA' ? null : (float) $rule->value;
                    $line['markupBase'] = round($base, 2);
                    if ($rule->rule_type === 'FORMULA') {
                        $line['markupFormula'] = $rule->formula;
                    }
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

                    if ($rule->rule_type === 'FORMULA' && $rule->formula) {
                        try {
                            $extra = round(ChargeFormulaEvaluator::evaluate($rule->formula, $valuesFor($code), $formulaContext), 2);
                        } catch (\Throwable $e) {
                            // No sensible base to fall back to for a brand-new line — skip it
                            // rather than inject a wrong/zero amount.
                            continue;
                        }
                    } else {
                        $extra = $rule->unit === 'PERCENTAGE'
                            ? round($sellSubtotal * ((float) $rule->value / 100), 2)
                            : round((float) $rule->value, 2);
                    }

                    if ($extra === 0.0) {
                        continue;
                    }

                    $breakdown[] = [
                        'code' => (string) $code,
                        'description' => $rule->chargeCode->label,
                        'amount' => $extra,
                        'currency' => $currency,
                        'isCustomCharge' => true,
                        'markupUnit' => $rule->rule_type === 'FORMULA' ? 'FORMULA' : $rule->unit,
                        'markupValue' => $rule->rule_type === 'FORMULA' ? null : (float) $rule->value,
                        // PERCENTAGE is computed off this quote's own sell subtotal; BAHT is a flat
                        // add with no base amount to show; FORMULA has no single "base" either.
                        'markupBase' => $rule->rule_type !== 'FORMULA' && $rule->unit === 'PERCENTAGE' ? round($sellSubtotal, 2) : null,
                        'markupFormula' => $rule->rule_type === 'FORMULA' ? $rule->formula : null,
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

    /** Evaluates a MarkupRule formula against an EXISTING line, falling back to that line's
     * base amount (override result or the carrier's own amount) if the formula fails. */
    private function safeEvaluateFormula(string $formula, array $rawAmountsByCode, float $fallback, array $context = []): float
    {
        try {
            return ChargeFormulaEvaluator::evaluate($formula, $rawAmountsByCode, $context);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
