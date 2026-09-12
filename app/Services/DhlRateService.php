<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class DhlRateService
{
    private function generateMessageReference(): string
    {
        return 'madd-' . now()->timestamp . '-' . bin2hex(random_bytes(4));
    }

    private function buildAddressDetails(array $addr): array
    {
        return [
            'postalCode' => $addr['postcode'] ?? '',
            'cityName' => $addr['city'] ?? '',
            'addressLine1' => $addr['address'] ?? '',
            'countryCode' => $addr['country'] ?? '',
        ];
    }

    /**
     * DHL has no UPS-style "Letter/Document" packaging concept — omitting dimensions
     * does NOT make DHL use just the actual weight, it silently substitutes a generic
     * ~2kg default parcel profile instead. So dimensions must ALWAYS be sent.
     */
    private function buildRateRequest(array $account, array $shipment): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];
        // Customs declaration is needed if ANY package in the shipment is a non-document box —
        // a single shipment can mix documents and boxes, so this isn't a shipment-wide flag.
        $hasNonDocumentPackage = collect($shipment['packages'])->contains(fn ($pkg) => empty($pkg['isDocument']));

        $expandedPackages = [];
        foreach ($shipment['packages'] as $pkg) {
            $quantity = (int) ($pkg['quantity'] ?? 1);
            $perUnitWeight = round(((float) ($pkg['weight'] ?? 0)) / max($quantity, 1), 2);
            for ($i = 0; $i < $quantity; $i++) {
                $expandedPackages[] = [
                    'typeCode' => '3BX',
                    'weight' => $perUnitWeight,
                    'dimensions' => [
                        'length' => (float) $pkg['length'],
                        'width' => (float) $pkg['width'],
                        'height' => (float) $pkg['height'],
                    ],
                ];
            }
        }

        return [
            'customerDetails' => [
                'shipperDetails' => $this->buildAddressDetails($from),
                'receiverDetails' => $this->buildAddressDetails($to),
            ],
            'accounts' => [['typeCode' => 'shipper', 'number' => $account['username_acc']]],
            'valueAddedServices' => [['serviceCode' => 'SF']],
            'payerCountryCode' => $from['country'],
            'plannedShippingDateAndTime' => now()->toIso8601String(),
            'unitOfMeasurement' => 'metric',
            // Documents never need a customs declaration, even cross-border.
            'isCustomsDeclarable' => $hasNonDocumentPackage && $from['country'] !== $to['country'],
            'estimatedDeliveryDate' => ['isRequested' => true, 'typeCode' => 'QDDC'],
            'getAdditionalInformation' => [['typeCode' => 'allValueAddedServices', 'isRequested' => true]],
            'returnStandardProductsOnly' => false,
            'nextBusinessDay' => true,
            'productTypeCode' => 'all',
            'packages' => $expandedPackages,
        ];
    }

    private function getRate(array $account, array $shipment): array
    {
        $messageRef = $this->generateMessageReference();

        $response = Http::withBasicAuth($account['basic_auth_username'], $account['basic_auth_password'])
            ->withHeaders([
                'x-version' => '3.2.0',
                'Message-Reference' => $messageRef,
                'Message-Reference-Date' => now()->toRfc7231String(),
            ])
            ->timeout(20)
            ->post($this->dhlUrl($account['mode'] ?? null) . '/rates', $this->buildRateRequest($account, $shipment));

        if (! $response->successful()) {
            throw new \RuntimeException('DHL rate request failed: ' . ($response->json('detail') ?? $response->json('title') ?? $response->status()));
        }

        return $response->json();
    }

    /**
     * DHL uses a different base path for test (.../mydhlapi/test) vs production
     * (.../mydhlapi) credentials — accounts carry their own `mode` to pick the matching
     * base URL for every DHL call (rating, tracking).
     */
    private function dhlUrl(?string $mode): string
    {
        return $mode === 'test' ? config('services.dhl.api_url_test') : config('services.dhl.api_url');
    }

    private const DHL_CHARGE_LABELS = [
        'BASE' => 'Base Freight',
        'SF' => 'Direct Signature',
        'FF' => 'Fuel Surcharge',
        'YK' => '12:00 Premium',
        'OF' => 'Remote Area Delivery',
        'FD' => 'GoGreen Plus',
    ];

    private function describeDhlCharge(?array $item, string $code): string
    {
        if (isset(self::DHL_CHARGE_LABELS[$code])) return self::DHL_CHARGE_LABELS[$code];

        return ! empty($item['name']) ? "{$item['name']} (code {$code})" : "Other Charge (code {$code})";
    }

    /**
     * Normalize EVERY product DHL returns, filtering out $0 "not actually priced" products.
     */
    private function extractAllQuotes(array $raw): array
    {
        $products = $raw['products'] ?? [];
        if (empty($products)) {
            throw new \RuntimeException('No rates returned from DHL API');
        }

        $quotes = [];
        foreach ($products as $product) {
            $totalPriceList = $product['totalPrice'] ?? [];
            $billed = collect($totalPriceList)->firstWhere('currencyType', 'BILLC') ?? ($totalPriceList[0] ?? null);
            $currency = $billed['priceCurrency'] ?? 'THB';
            $total = isset($billed['price']) ? (float) $billed['price'] : null;

            $detailedBilling = collect($product['detailedPriceBreakdown'] ?? [])->firstWhere('currencyType', 'BILLC')
                ?? ($product['detailedPriceBreakdown'][0] ?? null);

            $chargeBreakdown = [];
            foreach ($detailedBilling['breakdown'] ?? [] as $item) {
                if (! isset($item['price'])) continue;
                $code = $item['serviceCode'] ?? $item['localServiceCode'] ?? $item['typeCode'] ?? 'BASE';
                $chargeBreakdown[] = [
                    'code' => $code,
                    'description' => $this->describeDhlCharge($item, $code),
                    'amount' => round((float) $item['price'], 2),
                    'currency' => $currency,
                ];
            }

            $quotes[] = [
                'serviceCode' => $product['productCode'] ?? null,
                'serviceLabel' => $product['productName'] ?? $product['localProductCode'] ?? $product['productCode'] ?? 'DHL Service',
                'currency' => $currency,
                'total' => $total,
                'billedWeight' => $product['weight']['provided'] ?? null,
                'billedWeightUnit' => $product['weight']['unitOfMeasurement'] ?? 'metric',
                'isCustomerAgreement' => ($product['isCustomerAgreement'] ?? false) === true,
                'transitDays' => $product['deliveryCapabilities']['totalTransitDays'] ?? null,
                'estimatedDelivery' => $product['deliveryCapabilities']['estimatedDeliveryDateAndTime'] ?? null,
                'chargeBreakdown' => $chargeBreakdown,
            ];
        }

        // DHL sometimes returns a product with total 0 and an empty breakdown when the
        // account isn't actually enabled/priced for it — not a real free rate.
        return array_values(array_filter($quotes, fn ($q) => $q['total'] !== null && $q['total'] > 0));
    }

    public function quoteAccount(array $account, array $shipment): array
    {
        try {
            $raw = $this->getRate($account, $shipment);
            $quotes = $this->extractAllQuotes($raw);

            return array_map(fn ($q) => [
                'carrier' => 'DHL',
                'accountId' => $account['id'],
                'username' => $account['username_acc'],
                'serviceCode' => $q['serviceCode'],
                'serviceLabel' => $q['serviceLabel'],
                'currency' => $q['currency'],
                'billedWeight' => $q['billedWeight'],
                'billedWeightUnit' => $q['billedWeightUnit'],
                'published' => null,
                'negotiated' => $q['total'],
                'isCustomerAgreement' => $q['isCustomerAgreement'],
                'transitDays' => $q['transitDays'],
                'estimatedDelivery' => $q['estimatedDelivery'],
                'chargeBreakdown' => $q['chargeBreakdown'],
                'error' => null,
            ], $quotes);
        } catch (\Throwable $e) {
            return [[
                'carrier' => 'DHL',
                'accountId' => $account['id'],
                'username' => $account['username_acc'],
                'serviceCode' => null,
                'serviceLabel' => 'DHL',
                'error' => $e->getMessage(),
            ]];
        }
    }

    /**
     * Quote MULTIPLE DHL accounts at once, firing every account's request
     * concurrently via Http::pool instead of one-by-one.
     */
    public function quoteAccounts(array $accounts, array $shipment): array
    {
        if (empty($accounts)) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => collect($accounts)->map(function ($account, $i) use ($pool, $shipment) {
            $messageRef = $this->generateMessageReference();

            return $pool->as((string) $i)
                ->withBasicAuth($account['basic_auth_username'], $account['basic_auth_password'])
                ->withHeaders([
                    'x-version' => '3.2.0',
                    'Message-Reference' => $messageRef,
                    'Message-Reference-Date' => now()->toRfc7231String(),
                ])
                ->timeout(20)
                ->post($this->dhlUrl($account['mode'] ?? null) . '/rates', $this->buildRateRequest($account, $shipment));
        })->all());

        $results = [];
        foreach ($accounts as $i => $account) {
            try {
                $response = $responses[(string) $i];
                if ($response instanceof \Throwable) throw $response;
                if (! $response->successful()) {
                    throw new \RuntimeException('DHL rate request failed: ' . ($response->json('detail') ?? $response->json('title') ?? $response->status()));
                }

                $quotes = $this->extractAllQuotes($response->json());
                foreach ($quotes as $q) {
                    $results[] = [
                        'carrier' => 'DHL',
                        'accountId' => $account['id'],
                        'username' => $account['username_acc'],
                        'serviceCode' => $q['serviceCode'],
                        'serviceLabel' => $q['serviceLabel'],
                        'currency' => $q['currency'],
                        'billedWeight' => $q['billedWeight'],
                        'billedWeightUnit' => $q['billedWeightUnit'],
                        'published' => null,
                        'negotiated' => $q['total'],
                        'isCustomerAgreement' => $q['isCustomerAgreement'],
                        'transitDays' => $q['transitDays'],
                        'estimatedDelivery' => $q['estimatedDelivery'],
                        'chargeBreakdown' => $q['chargeBreakdown'],
                        'error' => null,
                    ];
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'carrier' => 'DHL',
                    'accountId' => $account['id'],
                    'username' => $account['username_acc'],
                    'serviceCode' => null,
                    'serviceLabel' => 'DHL',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
