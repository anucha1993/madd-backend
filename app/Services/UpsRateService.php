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

    public function serviceLabels(): array
    {
        return self::SERVICE_CODES;
    }

    public function getAccessToken(string $clientId, string $clientSecret): string
    {
        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->timeout(15)
            ->post(config('services.ups.oauth_url'), ['grant_type' => 'client_credentials']);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException('UPS OAuth failed: ' . ($response->json('error_description') ?? $response->status()));
        }

        return $response->json('access_token');
    }

    private function buildRateRequest(array $shipment, string $negotiatedIndicator): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];
        $isDocument = $shipment['isDocument'];

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
                    'Package' => array_map(function (array $pkg) use ($isDocument) {
                        return array_merge([
                            'PackagingType' => ['Code' => $isDocument ? '01' : '02'],
                        ], $isDocument ? [] : [
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
                        ]);
                    }, $shipment['packages']),
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

    private const CHARGE_CODE_LABELS = [
        '375' => 'ค่าธรรมเนียมน้ำมัน (Fuel Surcharge)',
        '270' => 'ค่าธรรมเนียมจัดการเพิ่มเติม (Additional Handling)',
        '440' => 'ค่าธรรมเนียมพื้นที่ห่างไกล (Delivery Area Surcharge)',
    ];

    private function describeCharge(?array $item): string
    {
        if (! empty($item['SubType'])) return str_replace('_', ' ', $item['SubType']);
        if (! empty($item['Description'])) return $item['Description'];
        if (! empty($item['Code']) && isset(self::CHARGE_CODE_LABELS[$item['Code']])) return self::CHARGE_CODE_LABELS[$item['Code']];

        return ! empty($item['Code']) ? "ค่าธรรมเนียมอื่นๆ (code {$item['Code']})" : 'ค่าธรรมเนียมอื่นๆ';
    }

    private function buildBreakdown(?array $baseCharge, ?array $itemizedCharges, string $currency): array
    {
        $lines = [];
        if (isset($baseCharge['MonetaryValue'])) {
            $lines[] = [
                'code' => 'BASE',
                'description' => 'ค่าขนส่งพื้นฐาน (Base Freight)',
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

        $chargeBreakdown = $this->buildBreakdown($rs['BaseServiceCharge'] ?? null, $rs['ItemizedCharges'] ?? null, $currency);
        $negotiatedChargeBreakdown = isset($rs['NegotiatedRateCharges'])
            ? $this->buildBreakdown($rs['NegotiatedRateCharges']['BaseServiceCharge'] ?? null, $rs['NegotiatedRateCharges']['ItemizedCharges'] ?? null, $currency)
            : null;

        return [
            'serviceCode' => $rs['Service']['Code'] ?? null,
            'serviceDescription' => $rs['Service']['Description'] ?? null,
            'currency' => $currency,
            'published' => $published !== null ? (float) $published : null,
            'negotiated' => $negotiated !== null ? (float) $negotiated : null,
            'billedWeight' => $rs['BillingWeight']['Weight'] ?? null,
            'billedWeightUnit' => $rs['BillingWeight']['UnitOfMeasurement']['Code'] ?? null,
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
                ->post(config('services.ups.oauth_url'), ['grant_type' => 'client_credentials'])
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

        $specs = [];
        foreach ($accounts as $i => $account) {
            if (! isset($tokens[$i])) continue;
            $shipperNumber = strtoupper($account['username_acc'] ?? '');
            foreach ($serviceCodes as $serviceCode) {
                foreach (['', 'Y'] as $indicator) {
                    $shipmentForCall = array_merge($shipment, ['shipperNumber' => $shipperNumber, 'serviceCode' => $serviceCode]);
                    $specs["{$i}:{$serviceCode}:{$indicator}"] = [
                        'token' => $tokens[$i],
                        'body' => $this->buildRateRequest($shipmentForCall, $indicator),
                    ];
                }
            }
        }

        $rateResponses = empty($specs) ? [] : Http::pool(fn (Pool $pool) => collect($specs)->map(
            fn ($spec, $key) => $pool->as($key)->withToken($spec['token'])->timeout(20)->post(config('services.ups.rate_url'), $spec['body'])
        )->all());

        $results = [];
        foreach ($accounts as $i => $account) {
            if (! isset($tokens[$i])) {
                foreach ($serviceCodes as $serviceCode) {
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

            foreach ($serviceCodes as $serviceCode) {
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
                        'published' => $publishedQuote['published'] ?? $negotiatedQuote['published'] ?? null,
                        'negotiated' => $negotiatedQuote['negotiated'] ?? $publishedQuote['negotiated'] ?? null,
                        'chargeBreakdown' => $negotiatedQuote['negotiatedChargeBreakdown'] ?? $publishedQuote['chargeBreakdown'],
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

        return $results;
    }
}
