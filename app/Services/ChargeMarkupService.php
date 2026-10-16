<?php

namespace App\Services;

use App\Models\ChargeFixedOverride;
use App\Models\Country;
use App\Models\MarkupRule;
use App\Models\ZonePrice;

class ChargeMarkupService
{
    /**
     * The carrier's own insurance lines — never part of Freight (the booking form nets them out).
     * A Fixed Charge on one sets the insurance's sell price (read by the carrier-own Insurance
     * Add-on); Mark-up rules never touch them (the Add-on has its own Markup %). The real cost
     * stays in costBreakdown. 'IB' is DHL's document-only Extended Liability service (see DhlRateService). Public so
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
        $weightMarkups = app(WeightMarkupService::class);
        $heavyRates = app(HeavyRateService::class);

        return array_map(
            fn ($result) => $this->applyAccountPricing($result, $overridesByAccount, $rulesByAccount, $formulaContext, $packageWeights, $destinationCountry, $zoneLookups, $weightMarkups, $packages, $heavyRates),
            $results,
        );
    }

    /** What the carrier billed (or its volumetric weight), never below what was declared. */
    private static function chargeableWeight(array $result, array $packages): float
    {
        $declared = collect($packages)->sum(fn ($p) => (float) ($p['weight'] ?? 0) * max(1, (int) ($p['quantity'] ?? 1)));

        return max((float) ($result['billedWeight'] ?? 0), (float) ($result['volumetricWeight'] ?? 0), $declared);
    }

    /**
     * One quote's sell price, in order: the account's Fixed Charges + Mark-up rules on the carrier's
     * lines → Heavy Weight Rate (replaces the lines picked at /config/heavy-rates, e.g. freight +
     * fuel) → Weight Markup → Mark-up rules that add their own line (e.g. "Total" 7%), so a
     * "% of subtotal" line is computed on everything before it, Weight Markup included.
     */
    private function applyAccountPricing(array $result, $overridesByAccount, $rulesByAccount, array $formulaContext, array $packageWeights, ?string $destinationCountry, array &$zoneLookups, WeightMarkupService $weightMarkups, array $packages, HeavyRateService $heavyRates): array
    {
        return (function ($result) use ($overridesByAccount, $rulesByAccount, $formulaContext, $packageWeights, $destinationCountry, &$zoneLookups, $weightMarkups, $packages, $heavyRates) {
            if (! empty($result['error'])) {
                return $result;
            }
            if (empty($result['accountId'])) {
                return $this->addLine($result, $this->weightMarkupLine($result, $weightMarkups, $packages, $destinationCountry, (float) ($result['negotiated'] ?? $result['published'] ?? 0)));
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
            // Same value under its clearer name — these formulas run before any sell price exists, so
            // here TOTAL can only be the carrier's own total.
            $rawAmountsByCode['COST'] = $rawAmountsByCode['TOTAL'];
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
                if ($code === null) {
                    return $line;
                }
                // The carrier's own insurance lines (COST_ONLY_CODES) take a Fixed Charge — that
                // becomes the insurance's sell price (its carrier-own Insurance Add-on reads this
                // line) — but never a Mark-up rule: insurance markup is the Add-on's own Markup %.
                // They stay out of Freight either way (the booking form nets them out).
                $isCostOnly = in_array($code, self::COST_ONLY_CODES, true);
                $override = $accountOverrides?->get($code);
                $rule = $isCostOnly ? null : $accountRules?->get($code);
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

            // Heavy Weight Rate (e.g. 71 kg+ = 150 THB/kg): its line replaces the priced lines picked
            // for this carrier (freight + fuel by default) — the rest (remote area, demand surcharge …)
            // stays. Lines of ours it replaces (WEIGHT_MARKUP, a rule line like TOTAL) are skipped below.
            $heavy = $heavyRates->resolve($carrier, $destinationCountry, self::chargeableWeight($result, $packages));
            $replaced = $heavy ? array_map('strval', $heavyRates->replacedCodes($carrier)) : [];
            if ($heavy) {
                $breakdown = array_values(array_filter($breakdown, function ($line) use ($replaced, &$delta) {
                    $code = (string) ($line['code'] ?? '');
                    if (! in_array($code, $replaced, true) || in_array($code, self::COST_ONLY_CODES, true)) {
                        return true;
                    }
                    $delta -= (float) $line['amount'];

                    return false;
                }));
                array_unshift($breakdown, [
                    'code' => 'HEAVY_RATE',
                    'description' => 'Heavy Weight Rate',
                    'amount' => $heavy['amount'],
                    'currency' => $breakdown[0]['currency'] ?? ($result['currency'] ?? 'THB'),
                    'isCustomCharge' => true,
                    'markupUnit' => 'PER_KG',
                    'markupValue' => $heavy['rate'],
                    'markupBase' => $heavy['weight'],
                    'markupNote' => "{$heavy['band']} · {$heavy['scope']}",
                ]);
                $delta += $heavy['amount'];
            }

            // Weight Markup goes here — after the carrier's lines are priced, before the rule lines
            // below, so a "% of subtotal" rule (e.g. "Total" 7%) includes it. Its {TOTAL} is the
            // sell total so far.
            $weightMarkup = in_array('WEIGHT_MARKUP', $replaced, true) ? null : $this->weightMarkupLine($result, $weightMarkups, $packages, $destinationCountry, (float) ($result['negotiated'] ?? $result['published'] ?? 0) + $delta);
            if ($weightMarkup) {
                $breakdown[] = $weightMarkup;
                $delta += $weightMarkup['amount'];
                $markupTotal += $weightMarkup['amount'];
            }

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
                    if (in_array((string) $code, $existingCodes, true) || in_array((string) $code, $replaced, true)) {
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
            if ($heavy) {
                // Everything above the carrier's own total is margin (or a loss, below it).
                $result['markupTotal'] = round((float) ($result['negotiated'] ?? $result['published'] ?? 0) - (float) ($result['costNegotiated'] ?? $result['costPublished'] ?? 0), 2);
                $result['heavyRate'] = true;
            }

            return $result;
        })($result);
    }

    /**
     * Weight Markup (/config/weight-markups) for one quote, as its own "Weight Markup" line — so it
     * shows up in the quote, in Create Shipment's sell price and in the Rate Book alike. Null when no
     * band / rate applies. $totalSoFar = the sell total before this line ({TOTAL} in its formulas).
     */
    private function weightMarkupLine(array $result, WeightMarkupService $weightMarkups, array $packages, ?string $destinationCountry, float $totalSoFar): ?array
    {
        if (empty($result['carrier'])) {
            return null;
        }

        $carrier = strtoupper((string) $result['carrier']);
        $packageType = $packages !== [] && collect($packages)->every(fn ($p) => ! empty($p['isDocument'])) ? 'document' : 'box';
        $weight = self::chargeableWeight($result, $packages);
        $zone = $destinationCountry && in_array($carrier, ['UPS', 'DHL'], true)
            ? Country::where('iso2', strtoupper($destinationCountry))->value(strtolower($carrier).'_zone')
            : null;

        // Formula variables: the carrier's own amount per code, TOTAL = the sell total so far (the
        // carrier's lines after Fixed Charges + Mark-up) and COST = the carrier's own total.
        $values = [];
        foreach ($result['costBreakdown'] ?? $result['chargeBreakdown'] ?? [] as $line) {
            if (isset($line['code'])) {
                $values[(string) $line['code']] = (float) ($line['amount'] ?? 0);
            }
        }
        $values += [
            'BILLED_WEIGHT' => $weight,
            'TOTAL' => $totalSoFar,
            'COST' => (float) ($result['costNegotiated'] ?? $result['costPublished'] ?? $result['negotiated'] ?? $result['published'] ?? 0),
            'BOX' => (float) collect($packages)->sum(fn ($p) => max(1, (int) ($p['quantity'] ?? 1))),
        ];
        if (is_numeric($zone)) {
            $values['ZONE'] = (float) $zone;
        }
        $boxWeights = [];
        foreach ($packages as $p) {
            for ($i = 0; $i < max(1, (int) ($p['quantity'] ?? 1)); $i++) {
                $boxWeights[] = (float) ($p['weight'] ?? 0);
            }
        }

        $markup = $weightMarkups->resolve($carrier, $packageType, $result['accountId'] ?? null, $destinationCountry, $zone, $weight, $values, ['package_weights' => $boxWeights]);
        if (! $markup) {
            return null;
        }

        return [
            'code' => 'WEIGHT_MARKUP',
            'description' => 'Weight Markup',
            'amount' => $markup['amount'],
            'currency' => $result['currency'] ?? 'THB',
            'isCustomCharge' => true,
            'markupUnit' => $markup['formula'] ? 'FORMULA' : 'PER_KG',
            'markupValue' => $markup['rate'],
            'markupBase' => $markup['formula'] ? null : $markup['weight'],
            'markupFormula' => $markup['formula'],
            'markupNote' => "{$markup['band']} · {$markup['scope']}",
        ];
    }

    /** Appends a priced line to a quote whose other lines are final, moving its totals with it. */
    private function addLine(array $result, ?array $line): array
    {
        if (! $line) {
            return $result;
        }
        $result['chargeBreakdown'] = [...($result['chargeBreakdown'] ?? []), $line];
        foreach (['published', 'negotiated'] as $total) {
            if (isset($result[$total]) && $result[$total] !== null) {
                $result[$total] = round($result[$total] + $line['amount'], 2);
            }
        }
        $result['markupTotal'] = round(($result['markupTotal'] ?? 0) + $line['amount'], 2);

        return $result;
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
