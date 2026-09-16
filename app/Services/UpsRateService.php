<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class UpsRateService
{
    private const SERVICE_CODES = [
        '65' => 'Worldwide Saver',
        '07' => 'Worldwide Express',
        '08' => 'Worldwide Expedited',
        '11' => 'UPS Standard',
    ];

    // The Rating API (SERVICE_CODES above) and the Time In Transit API use two
    // DIFFERENT numbering schemes for the same products (e.g. Rating "65" vs TnT
    // "28" both mean "Worldwide Saver") — so transit-time results must be matched
    // by service NAME, not by code. Each entry lists the exact TnT
    // serviceLevelDescription value(s) known to correspond to that rating service.
    private const SERVICE_NAME_ALIASES = [
        'Worldwide Saver' => ['UPS Worldwide Saver', 'UPS Express Saver'],
        'Worldwide Express' => ['UPS Worldwide Express'],
        'Worldwide Expedited' => ['UPS Worldwide Expedited'],
        'UPS Standard' => ['UPS Standard'],
    ];

    public function serviceLabels(): array
    {
        return self::SERVICE_CODES;
    }

    public function getAccessToken(string $clientId, string $clientSecret, ?string $mode = null): string
    {
        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->timeout(15)
            ->post($this->upsUrl('oauth_url', $mode), ['grant_type' => 'client_credentials']);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException('UPS OAuth failed: ' . ($response->json('error_description') ?? $response->status()));
        }

        return $response->json('access_token');
    }

    /**
     * UPS uses a completely different host for test (wwwcie.ups.com) vs production
     * (onlinetools.ups.com) credentials — accounts carry their own `mode` to pick the
     * matching host for every UPS call (OAuth, rating, tracking).
     */
    private function upsUrl(string $key, ?string $mode): string
    {
        $suffix = $mode === 'test' ? '_test' : '';

        return config("services.ups.{$key}{$suffix}");
    }

    private function buildRateRequest(array $shipment, string $negotiatedIndicator): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];

        return [
            'RateRequest' => [
                'Request' => ['TransactionReference' => ['CustomerContext' => 'MADD Shipment']],
                'Shipment' => [
                    'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => $negotiatedIndicator],
                    'Shipper' => [
                        'ShipperNumber' => $shipment['shipperNumber'] ?? null,
                        'Address' => [
                            'AddressLine' => [$from['address'] ?? ''],
                            'City' => $from['city'] ?? '',
                            'PostalCode' => $from['postcode'] ?? '',
                            'CountryCode' => $from['country'] ?? '',
                        ],
                    ],
                    'ShipTo' => [
                        'Address' => array_filter([
                            'AddressLine' => [$to['address'] ?? ''],
                            'City' => $to['city'] ?? '',
                            'StateProvinceCode' => $to['stateCode'] ?? null,
                            'PostalCode' => $to['postcode'] ?? '',
                            'CountryCode' => $to['country'] ?? '',
                        ], fn ($v) => $v !== null),
                    ],
                    'ShipFrom' => [
                        'Address' => [
                            'AddressLine' => [$from['address'] ?? ''],
                            'City' => $from['city'] ?? '',
                            'PostalCode' => $from['postcode'] ?? '',
                            'CountryCode' => $from['country'] ?? '',
                        ],
                    ],
                    'Service' => ['Code' => (string) ($shipment['serviceCode'] ?? '65')],
                    // UPS Letter/Document (01) has no Dimensions; Customer Supplied Package (02) requires them.
                    // Each package carries its own isDocument flag — a single shipment can mix documents and boxes.
                    // Declared Value is a PACKAGE-level field (PackageServiceOptions) — each package declares
                    // its own value, since a shipment's boxes can genuinely contain very different values.
                    // A package ROW's `quantity` means N physical identical boxes — UPS has no per-package
                    // "quantity" field, so each row must be expanded into `quantity` separate Package entries
                    // (each at the row's per-box weight) or UPS rates as if only ONE box exists.
                    'Package' => collect($shipment['packages'])->flatMap(function (array $pkg) use ($shipment) {
                        $pkgIsDocument = (bool) ($pkg['isDocument'] ?? false);
                        $pkgDeclaredValue = (float) ($pkg['declaredValue'] ?? 0);
                        $quantity = max((int) ($pkg['quantity'] ?? 1), 1);

                        $packageEntry = array_merge([
                            'PackagingType' => ['Code' => $pkgIsDocument ? '01' : '02'],
                        ], $pkgIsDocument ? [] : [
                            'Dimensions' => [
                                'UnitOfMeasurement' => ['Code' => $pkg['dimensionUnit'] ?? 'CM'],
                                'Length' => (string) ($pkg['length'] ?? ''),
                                'Width' => (string) ($pkg['width'] ?? ''),
                                'Height' => (string) ($pkg['height'] ?? ''),
                            ],
                        ], [
                            'PackageWeight' => [
                                'UnitOfMeasurement' => ['Code' => $pkg['weightUnit'] ?? 'KGS'],
                                'Weight' => (string) ($pkg['weight'] ?? ''),
                            ],
                        ], $pkgDeclaredValue > 0 && ! $pkgIsDocument ? [
                            // UPS does not cover Document shipments with its own Declared Value
                            // insurance at all — never send it for a document package, even if
                            // the caller passed a declaredValue (confirmed business rule, not
                            // just a UI restriction — see selectPackageInsurance's frontend guard).
                            'PackageServiceOptions' => [
                                'DeclaredValue' => [
                                    'CurrencyCode' => $shipment['declaredValueCurrency'] ?? 'THB',
                                    'MonetaryValue' => number_format($pkgDeclaredValue, 2, '.', ''),
                                ],
                            ],
                        ] : []);

                        return array_fill(0, $quantity, $packageEntry);
                    })->all(),
                ],
            ],
        ];
    }

    private function getRate(string $token, array $shipment, string $negotiatedIndicator): array
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->post(config('services.ups.rate_url'), $this->buildRateRequest($shipment, $negotiatedIndicator));

        if (! $response->successful()) {
            throw new \RuntimeException('UPS rate request failed: ' . ($response->json('response.errors.0.message') ?? $response->status()));
        }

        return $response->json();
    }

    private function buildTimeInTransitRequest(array $shipment): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];
        $isInternational = ($from['country'] ?? '') !== ($to['country'] ?? '');
        $hasNonDocument = collect($shipment['packages'])->contains(fn ($pkg) => empty($pkg['isDocument']));
        $totalWeight = collect($shipment['packages'])->sum(fn ($pkg) => ((float) ($pkg['weight'] ?? 0)) * max(1, (int) ($pkg['quantity'] ?? 1)));

        $payload = array_filter([
            'originCountryCode' => $from['country'] ?? null,
            'originCityName' => $from['city'] ?? null,
            'originPostalCode' => $from['postcode'] ?? null,
            'destinationCountryCode' => $to['country'] ?? null,
            'destinationCityName' => $to['city'] ?? null,
            'destinationPostalCode' => $to['postcode'] ?? null,
            'residentialIndicator' => '02',
            'shipDate' => now()->addDay()->format('Y-m-d'),
            'weight' => (string) max($totalWeight, 0.1),
            'weightUnitOfMeasure' => 'KGS',
            'billType' => $hasNonDocument ? '03' : '02',
        ], fn ($v) => $v !== null && $v !== '');

        // UPS requires a declared value for international non-document shipments — a
        // placeholder is fine here since this call only estimates transit time, not price.
        if ($isInternational && $hasNonDocument) {
            $totalDeclaredValue = collect($shipment['packages'])->sum(fn ($pkg) => (float) ($pkg['declaredValue'] ?? 0));
            $payload['shipmentContentsValue'] = (string) ($totalDeclaredValue > 0 ? $totalDeclaredValue : 1);
            $payload['shipmentContentsCurrencyCode'] = $shipment['declaredValueCurrency'] ?? 'USD';
        }

        return $payload;
    }

    /**
     * Estimated transit days / delivery date per UPS service, via the Time In Transit
     * API — the Rating API alone does not reliably return this for every service.
     * Returns a map keyed by the exact serviceLevelDescription text (see
     * SERVICE_NAME_ALIASES for why matching must happen by name, not by code).
     */
    public function getTimeInTransit(string $token, array $shipment, ?string $mode = null): array
    {
        $response = Http::withToken($token)
            ->withHeaders([
                'transId' => (string) \Illuminate\Support\Str::uuid(),
                'transactionSrc' => config('services.ups.transaction_src', 'testing'),
            ])
            ->timeout(15)
            ->post($this->upsUrl('time_in_transit_url', $mode), $this->buildTimeInTransitRequest($shipment));

        if (! $response->successful()) {
            throw new \RuntimeException('UPS Time In Transit request failed: ' . ($response->json('response.errors.0.message') ?? $response->status()));
        }

        $byDescription = [];
        foreach ($response->json('emsResponse.services') ?? [] as $service) {
            $description = trim((string) ($service['serviceLevelDescription'] ?? ''));
            if ($description === '' || isset($byDescription[$description])) continue;

            $byDescription[$description] = [
                'transitDays' => $service['totalTransitDays'] ?? $service['businessTransitDays'] ?? null,
                'estimatedDelivery' => $service['deliveryDate'] ?? null,
                'guaranteed' => ($service['guaranteeIndicator'] ?? '0') === '1',
            ];
        }

        return $byDescription;
    }

    /**
     * Look up a rating service's transit-time info from the TnT-by-description map,
     * trying each known name alias for that service in priority order.
     */
    private function findTimeInTransit(array $byDescription, string $serviceLabel): ?array
    {
        foreach (self::SERVICE_NAME_ALIASES[$serviceLabel] ?? [] as $alias) {
            if (isset($byDescription[$alias])) {
                return $byDescription[$alias];
            }
        }

        return null;
    }

    private const CHARGE_CODE_LABELS = [
        '375' => 'Fuel Surcharge',
        '270' => 'Additional Handling',
        '440' => 'Delivery Area Surcharge',
        '400' => 'Declared Value (Insurance)', // SubType "EVS" = Excess Value Surcharge
    ];

    private function describeCharge(?array $item): string
    {
        if (! empty($item['Code']) && isset(self::CHARGE_CODE_LABELS[$item['Code']])) return self::CHARGE_CODE_LABELS[$item['Code']];
        if (! empty($item['SubType'])) return str_replace('_', ' ', $item['SubType']);
        if (! empty($item['Description'])) return $item['Description'];
        if (! empty($item['Code']) && isset(self::CHARGE_CODE_LABELS[$item['Code']])) return self::CHARGE_CODE_LABELS[$item['Code']];

        return ! empty($item['Code']) ? "Other Charge (code {$item['Code']})" : 'Other Charge';
    }

    private function buildBreakdown(?array $baseCharge, ?array $itemizedCharges, string $currency, ?array $serviceOptionsCharge = null): array
    {
        $lines = [];
        if (isset($baseCharge['MonetaryValue'])) {
            $lines[] = [
                'code' => 'BASE',
                'description' => 'Base Freight',
                'amount' => (float) $baseCharge['MonetaryValue'],
                'currency' => $baseCharge['CurrencyCode'] ?? $currency,
            ];
        }
        foreach ($itemizedCharges ?? [] as $item) {
            $lines[] = [
                'code' => $item['Code'] ?? null,
                'description' => $this->describeCharge($item),
                'amount' => (float) ($item['MonetaryValue'] ?? 0),
                'currency' => $item['CurrencyCode'] ?? $currency,
            ];
        }
        // ServiceOptionsCharges (e.g. Declared Value insurance) is usually already broken out
        // in ItemizedCharges as Code 400 — only add this as a fallback if it isn't there yet.
        $hasDeclaredValueLine = collect($itemizedCharges ?? [])->contains(fn ($item) => ($item['Code'] ?? null) === '400');
        if (! $hasDeclaredValueLine && isset($serviceOptionsCharge['MonetaryValue']) && (float) $serviceOptionsCharge['MonetaryValue'] > 0) {
            $lines[] = [
                'code' => 'SERVICE_OPTIONS',
                'description' => 'Declared Value (Insurance)',
                'amount' => (float) $serviceOptionsCharge['MonetaryValue'],
                'currency' => $serviceOptionsCharge['CurrencyCode'] ?? $currency,
            ];
        }

        return $lines;
    }

    private function extractQuote(array $raw): array
    {
        $ratedShipments = $raw['RateResponse']['RatedShipment'] ?? [];
        $rs = array_is_list($ratedShipments) ? ($ratedShipments[0] ?? null) : $ratedShipments;

        if (! $rs) {
            throw new \RuntimeException('No RatedShipment returned by UPS');
        }

        $currency = $rs['TotalCharges']['CurrencyCode'] ?? $rs['TransportationCharges']['CurrencyCode'] ?? 'THB';
        $published = $rs['TotalCharges']['MonetaryValue'] ?? $rs['TransportationCharges']['MonetaryValue'] ?? null;
        $negotiated = $rs['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? null;

        $chargeBreakdown = $this->buildBreakdown($rs['BaseServiceCharge'] ?? null, $rs['ItemizedCharges'] ?? null, $currency, $rs['ServiceOptionsCharges'] ?? null);
        $negotiatedChargeBreakdown = isset($rs['NegotiatedRateCharges'])
            ? $this->buildBreakdown($rs['NegotiatedRateCharges']['BaseServiceCharge'] ?? null, $rs['NegotiatedRateCharges']['ItemizedCharges'] ?? null, $currency, $rs['NegotiatedRateCharges']['ServiceOptionsCharges'] ?? $rs['ServiceOptionsCharges'] ?? null)
            : null;


        return [
            'serviceCode' => $rs['Service']['Code'] ?? null,
            'serviceDescription' => $rs['Service']['Description'] ?? null,
            'currency' => $currency,
            'published' => $published !== null ? (float) $published : null,
            'negotiated' => $negotiated !== null ? (float) $negotiated : null,
            'billedWeight' => $rs['BillingWeight']['Weight'] ?? null,
            'billedWeightUnit' => $rs['BillingWeight']['UnitOfMeasurement']['Code'] ?? null,
            'zone' => $rs['Zone'] ?? null,
            'chargeBreakdown' => $chargeBreakdown,
            'negotiatedChargeBreakdown' => $negotiatedChargeBreakdown,
        ];
    }

    /**
     * Quote every requested service code for MULTIPLE UPS accounts at once,
     * firing all OAuth + rate requests concurrently via Http::pool (instead of
     * sequentially — with N accounts x M service codes x 2 (published/negotiated)
     * calls, sequential requests would take far too long).
     */
    public function quoteAccounts(array $accounts, array $shipment, array $serviceCodes): array
    {
        if (empty($accounts)) {
            return [];
        }

        $tokenResponses = Http::pool(fn (Pool $pool) => collect($accounts)->map(
            fn ($account, $i) => $pool->as((string) $i)->asForm()
                ->withBasicAuth($account['client_id'], $account['client_secret'])
                ->timeout(15)
                ->post($this->upsUrl('oauth_url', $account['mode'] ?? null), ['grant_type' => 'client_credentials'])
        )->all());

        $tokens = [];
        $tokenErrors = [];
        foreach ($accounts as $i => $account) {
            $resp = $tokenResponses[(string) $i];
            if ($resp instanceof \Throwable) {
                $tokenErrors[$i] = $resp->getMessage();
            } elseif ($resp->successful() && $resp->json('access_token')) {
                $tokens[$i] = $resp->json('access_token');
            } else {
                $tokenErrors[$i] = 'UPS OAuth failed: ' . ($resp->json('error_description') ?? $resp->status());
            }
        }

        // Each account may restrict itself to a subset of the requested service codes (see
        // BranchCarrierAccount.allowed_service_codes) — empty/missing means no restriction.
        $accountServiceCodes = [];
        foreach ($accounts as $i => $account) {
            $allowed = $account['allowed_service_codes'] ?? null;
            $accountServiceCodes[$i] = $allowed ? array_values(array_intersect($serviceCodes, $allowed)) : $serviceCodes;
        }

        $specs = [];
        foreach ($accounts as $i => $account) {
            if (! isset($tokens[$i])) continue;
            $shipperNumber = strtoupper($account['username_acc'] ?? '');
            foreach ($accountServiceCodes[$i] as $serviceCode) {
                foreach (['', 'Y'] as $indicator) {
                    $shipmentForCall = array_merge($shipment, ['shipperNumber' => $shipperNumber, 'serviceCode' => $serviceCode]);
                    $specs["{$i}:{$serviceCode}:{$indicator}"] = [
                        'token' => $tokens[$i],
                        'mode' => $account['mode'] ?? null,
                        'body' => $this->buildRateRequest($shipmentForCall, $indicator),
                    ];
                }
            }
        }

        $rateResponses = empty($specs) ? [] : Http::pool(fn (Pool $pool) => collect($specs)->map(
            fn ($spec, $key) => $pool->as($key)->withToken($spec['token'])->timeout(20)->post($this->upsUrl('rate_url', $spec['mode']), $spec['body'])
        )->all());

        $results = [];
        foreach ($accounts as $i => $account) {
            if (! isset($tokens[$i])) {
                foreach ($accountServiceCodes[$i] as $serviceCode) {
                    $results[] = [
                        'carrier' => 'UPS',
                        'accountId' => $account['id'],
                        'username' => $account['username_acc'],
                        'serviceCode' => $serviceCode,
                        'serviceLabel' => self::SERVICE_CODES[$serviceCode] ?? $serviceCode,
                        'error' => $tokenErrors[$i] ?? 'UPS OAuth failed',
                    ];
                }
                continue;
            }

            foreach ($accountServiceCodes[$i] as $serviceCode) {
                try {
                    $pubResp = $rateResponses["{$i}:{$serviceCode}:"];
                    $negResp = $rateResponses["{$i}:{$serviceCode}:Y"];
                    if ($pubResp instanceof \Throwable) throw $pubResp;
                    if ($negResp instanceof \Throwable) throw $negResp;
                    if (! $pubResp->successful()) {
                        throw new \RuntimeException('UPS rate request failed: ' . ($pubResp->json('response.errors.0.message') ?? $pubResp->status()));
                    }
                    if (! $negResp->successful()) {
                        throw new \RuntimeException('UPS rate request failed: ' . ($negResp->json('response.errors.0.message') ?? $negResp->status()));
                    }

                    $publishedQuote = $this->extractQuote($pubResp->json());
                    $negotiatedQuote = $this->extractQuote($negResp->json());

                    $results[] = [
                        'carrier' => 'UPS',
                        'accountId' => $account['id'],
                        'username' => $account['username_acc'],
                        'serviceCode' => $serviceCode,
                        'serviceLabel' => self::SERVICE_CODES[$serviceCode] ?? $publishedQuote['serviceDescription'] ?? $serviceCode,
                        'currency' => $publishedQuote['currency'],
                        'billedWeight' => $publishedQuote['billedWeight'],
                        'billedWeightUnit' => $publishedQuote['billedWeightUnit'],
                        'zone' => $publishedQuote['zone'] ?? $negotiatedQuote['zone'] ?? null,
                        'published' => $publishedQuote['published'] ?? $negotiatedQuote['published'] ?? null,
                        'negotiated' => $negotiatedQuote['negotiated'] ?? $publishedQuote['negotiated'] ?? null,
                        'chargeBreakdown' => $negotiatedQuote['negotiatedChargeBreakdown'] ?? $publishedQuote['chargeBreakdown'],
                        'raw' => ['published' => $pubResp->json(), 'negotiated' => $negResp->json()],
                        'error' => null,
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'carrier' => 'UPS',
                        'accountId' => $account['id'],
                        'username' => $account['username_acc'],
                        'serviceCode' => $serviceCode,
                        'serviceLabel' => self::SERVICE_CODES[$serviceCode] ?? $serviceCode,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        // Best-effort: transit time doesn't depend on the account, so one call
        // (using whichever account authenticated first) is enough for the whole
        // batch. Failures here must never break the price quotes themselves.
        if (! empty($tokens)) {
            $firstIndex = array_key_first($tokens);
            try {
                $transitByDescription = $this->getTimeInTransit($tokens[$firstIndex], $shipment, $accounts[$firstIndex]['mode'] ?? null);
                foreach ($results as &$result) {
                    if (empty($result['error'])) {
                        $transit = $this->findTimeInTransit($transitByDescription, $result['serviceLabel']);
                        if ($transit) {
                            $result['transitDays'] = $transit['transitDays'];
                            $result['estimatedDelivery'] = $transit['estimatedDelivery'];
                            $result['guaranteed'] = $transit['guaranteed'];
                        }
                    }
                }
                unset($result);
            } catch (\Throwable) {
                // Ignore — rate quotes still stand without a transit-time estimate.
            }
        }

        return $results;
    }
}
