<?php

namespace App\Services;

use App\Models\AgentAccount;
use App\Models\IntegrationSetting;
use App\Models\RateBookRow;
use App\Models\RateBookRun;
use App\Support\RateBookSettings;

/**
 * Builds one Rate Book snapshot: for every carrier × document/box × weight point × zone, asks
 * the carrier's rate API (rates only — nothing is ever booked), runs the result through the
 * account's own overrides/markup exactly like Create Shipment does (ChargeMarkupService), then
 * splits the sell price into carrier cost per column + markup (profit) + VAT. Display only: it
 * reads markup/overrides/zones but never changes them, and writes nothing but rate_book_rows.
 */
class RateBookService
{
    // Boxes are quoted at small dims and at most this much each, so heavy points don't pick up
    // Additional Handling / oversize charges that a normal shipment of that weight wouldn't.
    private const MAX_PIECE_KG = 20;

    // Pause between rate requests so a sync never crowds out staff's own Check Rate calls.
    private const PAUSE_MICROSECONDS = 300_000;

    private const ORIGIN = ['country' => 'TH', 'city' => 'Bangkok', 'postcode' => '10110', 'address' => '1 Sukhumvit Road'];

    // Carrier charge code → Rate Book column. Anything unlisted lands in "other" so the columns
    // always add up to the sell price.
    private const COLUMNS = [
        'UPS' => ['BASE' => 'freight', '375' => 'fuel', '434' => 'surge'],
        'DHL' => ['BASE' => 'freight', 'FF' => 'fuel', 'OF' => 'remote', 'NX' => 'peak', 'FD' => 'gogreen'],
    ];

    public function __construct(
        private UpsRateService $upsRateService,
        private DhlRateService $dhlRateService,
        private ChargeMarkupService $chargeMarkupService,
    ) {
    }

    /**
     * Every weight to quote, from the settings' bands.
     *
     * @return list<array{carrier:string, package_type:string, band_label:string, weight:float, is_per_kg:bool, account_id:?int, service_code:string}>
     */
    public static function points(array $settings): array
    {
        $points = [];
        foreach (RateBookSettings::CARRIERS as $carrier) {
            $carrierSettings = $settings['carriers'][$carrier] ?? null;
            if (! $carrierSettings || empty($carrierSettings['enabled'])) {
                continue;
            }
            foreach (RateBookSettings::PACKAGE_TYPES as $packageType) {
                foreach ($carrierSettings['bands'][$packageType] ?? [] as $band) {
                    $min = (float) $band['min'];
                    $max = isset($band['max']) && $band['max'] !== '' ? (float) $band['max'] : null;
                    $label = self::bandLabel($min, $max);
                    $step = isset($band['step']) && (float) $band['step'] > 0 ? (float) $band['step'] : null;
                    $weights = [];
                    if ($step !== null && $max !== null) {
                        // 0.10-5.00 step 0.5 → 0.5, 1.0 ... 5.0 (first multiple of step above min).
                        for ($w = ceil(round($min / $step, 6)) * $step; $w <= $max + 1e-9; $w += $step) {
                            $weights[] = round($w < $min ? $w + $step : $w, 2);
                        }
                    } else {
                        // Per-kg band: one quote at the band's first whole kg (20.01-44 → 21).
                        $weights[] = (float) (floor($min) + 1);
                    }
                    // Nothing above 10 kg is sold at a fractional weight — round those up to whole kg.
                    $weights = array_map(fn ($w) => $w > 10 ? (float) ceil($w) : $w, $weights);
                    foreach (array_unique($weights) as $weight) {
                        $points[] = [
                            'carrier' => $carrier,
                            'package_type' => $packageType,
                            'band_label' => $label,
                            'weight' => $weight,
                            'is_per_kg' => $step === null,
                            'account_id' => isset($band['account_id']) ? (int) $band['account_id'] : null,
                            'service_code' => (string) $carrierSettings['service_codes'][$packageType],
                        ];
                    }
                }
            }
        }

        return $points;
    }

    /** A zone rule (e.g. DHL zone 7 from 14 kg → another account) beats the band's own account. */
    public static function accountFor(array $rules, string $zone, float $weight, ?int $bandAccountId): ?int
    {
        $match = collect($rules)
            ->filter(fn ($rule) => (string) ($rule['zone'] ?? '') === $zone && $weight >= (float) ($rule['min_weight'] ?? 0) && ! empty($rule['account_id']))
            ->sortByDesc(fn ($rule) => (float) $rule['min_weight'])
            ->first();

        return $match ? (int) $match['account_id'] : $bandAccountId;
    }

    /** BaseServiceCharge of UPS's own published-rate response (UpsRateService keeps it in raw). */
    private static function upsPublishedFreight(array $result): ?float
    {
        $rated = $result['raw']['published']['RateResponse']['RatedShipment'] ?? null;
        if (is_array($rated) && array_is_list($rated)) {
            $rated = $rated[0] ?? null;
        }
        $value = $rated['BaseServiceCharge']['MonetaryValue'] ?? null;

        return $value !== null ? round((float) $value, 2) : null;
    }

    private static function isTransient(string $error): bool
    {
        return (bool) preg_match('/cURL error|timed out|timeout|Connection|could not resolve|\b5\d\d\b/i', $error);
    }

    public static function bandLabel(float $min, ?float $max): string
    {
        return number_format($min, 2).($max !== null ? '-'.number_format($max, 2) : '+').' kg';
    }

    public function run(RateBookRun $run): RateBookRun
    {
        $settings = $run->settings;
        $points = self::points($settings);
        $columnsByCarrier = [];
        foreach (RateBookSettings::CARRIERS as $carrier) {
            $columnsByCarrier[$carrier] = RateBookSettings::columns($settings, $carrier);
        }
        $total = collect($points)->sum(fn ($p) => count($columnsByCarrier[$p['carrier']]));
        $run->update(['total_points' => $total, 'started_at' => $run->started_at ?? now()]);

        $accounts = AgentAccount::with('agent')->get()->keyBy('id');
        $done = 0;
        $errors = 0;
        foreach ($points as $point) {
            foreach ($columnsByCarrier[$point['carrier']] as $column) {
                $accountId = self::accountFor($settings['carriers'][$point['carrier']]['account_rules'] ?? [], $column['zone'], $point['weight'], $point['account_id']);
                $row = $this->quotePoint($point, $column['key'], $column['address'], $accounts->get($accountId));
                if ($row['error'] !== null && self::isTransient($row['error'])) {
                    // Network blip (DNS / timeout / carrier 5xx) — one more try before giving up on the point.
                    sleep(app()->runningUnitTests() ? 0 : 3);
                    $row = $this->quotePoint($point, $column['key'], $column['address'], $accounts->get($accountId));
                }
                RateBookRow::create(['rate_book_run_id' => $run->id] + $row);
                usleep(app()->runningUnitTests() ? 0 : self::PAUSE_MICROSECONDS);
                $done++;
                if ($row['error'] !== null) {
                    $errors++;
                }
                // Progress (also the heartbeat closeStaleRuns() watches) + a chance to stop.
                if ($done % 5 === 0) {
                    $run->update(['done_points' => $done, 'error_points' => $errors]);
                    if ((string) IntegrationSetting::get(RateBookSettings::CANCEL_KEY) === (string) $run->id) {
                        IntegrationSetting::set(RateBookSettings::CANCEL_KEY, null);
                        $run->update(['status' => 'failed', 'error' => 'ยกเลิกโดยผู้ใช้', 'finished_at' => now()]);

                        return $run;
                    }
                }
            }
        }

        $run->update([
            'done_points' => $done,
            'error_points' => $errors,
            'status' => $errors === 0 ? 'success' : ($errors < $done ? 'partial' : 'failed'),
            'finished_at' => now(),
        ]);

        return $run;
    }

    private function quotePoint(array $point, string $zone, array $address, ?AgentAccount $account): array
    {
        $row = [
            'carrier' => $point['carrier'],
            'package_type' => $point['package_type'],
            'zone' => $zone,
            'country_iso2' => (string) ($address['iso2'] ?? ''),
            'band_label' => $point['band_label'],
            'weight' => $point['weight'],
            'is_per_kg' => $point['is_per_kg'],
            'agent_account_id' => $account?->id,
            'account_username' => $account?->username_acc,
            'service_code' => $point['service_code'],
            'error' => null,
        ];

        try {
            if (! $account || $account->agent?->agent_code !== $point['carrier']) {
                throw new \RuntimeException('ไม่ได้เลือกบัญชี '.$point['carrier'].' สำหรับช่วงน้ำหนักนี้');
            }
            if (empty($address['iso2']) || empty($address['city'])) {
                throw new \RuntimeException("Zone {$zone}: ยังไม่ได้ตั้งประเทศ/เมืองตัวแทน");
            }

            $isDocument = $point['package_type'] === 'document';
            $pieces = $isDocument ? 1 : max(1, (int) ceil($point['weight'] / self::MAX_PIECE_KG));
            $packages = [[
                'weight' => round($point['weight'] / $pieces, 2),
                'quantity' => $pieces,
                'isDocument' => $isDocument,
                'length' => $isDocument ? null : 10,
                'width' => $isDocument ? null : 10,
                'height' => $isDocument ? null : 10,
            ]];
            $shipment = [
                'from' => self::ORIGIN,
                'to' => [
                    'country' => $address['iso2'],
                    'city' => $address['city'],
                    'postcode' => $address['postcode'] ?? '',
                    'stateCode' => ($address['state_code'] ?? '') ?: null,
                    'address' => '1 Main Street',
                ],
                'packages' => $packages,
                'declaredValueCurrency' => 'THB',
                // Exactly what Check Rate sends when staff pick nothing: DHL's default Direct
                // Signature, no UPS options — so the prices match /shipment/create.
                'optionalServiceCodes' => DhlRateService::DEFAULT_OPTIONAL_SERVICE_CODES,
                'upsOptionalServiceCodes' => [],
            ];

            $result = $this->quote($point['carrier'], $account, $shipment, $point['service_code']);
            $result = $this->chargeMarkupService->applyToResults([$result], $packages, $address['iso2'])[0];

            $row = array_merge($row, $this->columns($point['carrier'], $result));
            if ($point['is_per_kg']) {
                foreach (['full', 'freight', 'fuel', 'surge', 'remote', 'peak', 'gogreen', 'other', 'cost', 'markup', 'rounding', 'sell', 'vat', 'total'] as $column) {
                    $row[$column] = $row[$column] === null ? null : round($row[$column] / $point['weight'], 2);
                }
            }
        } catch (\Throwable $e) {
            $row['error'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $row;
    }

    private function quote(string $carrier, AgentAccount $account, array $shipment, string $serviceCode): array
    {
        if ($carrier === 'UPS') {
            $result = $this->upsRateService->quoteAccounts([[
                'id' => $account->id,
                'username_acc' => $account->username_acc,
                'client_id' => $account->client_id,
                'client_secret' => $account->client_secret,
                'mode' => $account->mode,
            ]], $shipment, [$serviceCode])[0] ?? null;
        } else {
            $results = $this->dhlRateService->quoteAccounts([[
                'id' => $account->id,
                'username_acc' => $account->username_acc,
                'basic_auth_username' => $account->basic_auth_username,
                'basic_auth_password' => $account->basic_auth_password,
                'mode' => $account->mode,
            ]], $shipment);
            $failed = collect($results)->first(fn ($r) => ! empty($r['error']));
            $result = collect($results)->first(fn ($r) => empty($r['error']) && ($r['serviceCode'] ?? null) === $serviceCode)
                ?? ($failed ?: ['error' => "DHL ไม่มีบริการ {$serviceCode} สำหรับปลายทางนี้"]);
        }

        if (! $result || ! empty($result['error'])) {
            throw new \RuntimeException($result['error'] ?? 'No rate returned');
        }

        return $result;
    }

    /**
     * Splits the SELL price into Rate Book columns the way the rate-file template does: each
     * charge at its price AFTER the account's Fixed Charges/formulas (/config/agent-accounts —
     * e.g. fuel = BASE × 58%, surge = zone price × kg) but before Mark-up rules; "markup" = what
     * the Mark-up rules add (/config/markup, incl. self-defined extra lines); "rounding" = the
     * round-up to a whole baht Create Shipment applies (FREE). "cost" is the carrier's own total
     * and "full" UPS's published, pre-discount freight (FULL; DISC % = 1 − freight / full).
     */
    private function columns(string $carrier, array $result): array
    {
        $columns = array_fill_keys(['freight', 'fuel', 'surge', 'remote', 'peak', 'gogreen', 'other'], 0.0);
        foreach ($result['chargeBreakdown'] ?? [] as $line) {
            if (in_array($line['code'] ?? null, ChargeMarkupService::COST_ONLY_CODES, true) || ! empty($line['isCustomCharge'])) {
                continue;
            }
            $base = isset($line['markupBase']) ? (float) $line['markupBase'] : (float) $line['amount'];
            $columns[self::COLUMNS[$carrier][(string) ($line['code'] ?? '')] ?? 'other'] += $base;
        }
        $cost = collect($result['costBreakdown'] ?? $result['chargeBreakdown'] ?? [])
            ->reject(fn ($line) => in_array($line['code'] ?? null, ChargeMarkupService::COST_ONLY_CODES, true))
            ->sum(fn ($line) => (float) $line['amount']);

        $rawSell = round((float) ($result['negotiated'] ?? $result['published'] ?? 0), 2);
        $sell = (float) ceil($rawSell);
        $columns = array_map(fn ($v) => round($v, 2), $columns);

        return $columns + [
            'full' => $carrier === 'UPS' ? self::upsPublishedFreight($result) : null,
            'cost' => round($cost, 2),
            // Everything the charge columns don't hold (Mark-up rules) — so they always add up to the sell price.
            'markup' => round($rawSell - array_sum($columns), 2),
            'rounding' => round($sell - $rawSell, 2),
            'sell' => $sell,
            // The sell price IS the final price — exactly Create Shipment's (VAT is already built
            // into the account's Fixed Charges / Mark-up), so nothing is added on top.
            'vat' => null,
            'total' => $sell,
        ];
    }
}
