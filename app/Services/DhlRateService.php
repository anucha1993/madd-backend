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
        // DHL prices insurance completely differently for boxes vs documents: boxes use 'II'
        // (Insurance), a real percentage-of-value charge that needs its own value/currency.
        // Documents instead use 'IB' (Extended Liability) — a flat marketed service (confirmed
        // via a live DHL response's getAdditionalInformation/allValueAddedServices list; DHL's
        // own MyDHL portal shows this as a fixed lump-sum compensation, e.g. 17,000 THB, not
        // proportional to any declared value) so it carries NO value/currency at all.
        $declaredValue = collect($shipment['packages'])
            ->filter(fn ($pkg) => empty($pkg['isDocument']))
            ->sum(fn ($pkg) => (float) ($pkg['declaredValue'] ?? 0));
        $hasInsuredDocument = collect($shipment['packages'])
            ->contains(fn ($pkg) => ! empty($pkg['isDocument']) && (float) ($pkg['declaredValue'] ?? 0) > 0);

        $expandedPackages = [];
        foreach ($shipment['packages'] as $pkg) {
            $quantity = (int) ($pkg['quantity'] ?? 1);
            // $pkg['weight'] is the weight of ONE box (see Packages UI) — expand into `quantity`
            // separate DHL packages each at that same per-box weight, never divide it.
            // Document packages never collect length/width/height in the UI (see class doc
            // comment above — DHL has no "Letter/Document" concept and needs SOME real
            // dimensions or it returns no valid rates at all) — fall back to a standard
            // document envelope size (35x25x2 cm) instead of sending 0x0x0.
            for ($i = 0; $i < max($quantity, 1); $i++) {
                $expandedPackages[] = [
                    'typeCode' => '3BX',
                    'weight' => (float) ($pkg['weight'] ?? 0),
                    'dimensions' => [
                        'length' => (float) ($pkg['length'] ?? 35),
                        'width' => (float) ($pkg['width'] ?? 25),
                        'height' => (float) ($pkg['height'] ?? 2),
                    ],
                ];
            }
        }

        $valueAddedServices = collect($shipment['optionalServiceCodes'] ?? ['SF'])
            ->map(fn ($code) => ['serviceCode' => $code])->all();
        if ($declaredValue > 0) {
            // 'II' (Insurance) must carry its own value/currency (per DHL's rates schema
            // supermodelIoLogisticsExpressValueAddedServicesRates — serviceCode alone is not
            // enough) or DHL silently omits the insurance charge from detailedPriceBreakdown.
            $valueAddedServices[] = ['serviceCode' => 'II', 'value' => $declaredValue, 'currency' => $shipment['declaredValueCurrency'] ?? 'THB'];
        }
        if ($hasInsuredDocument) {
            $valueAddedServices[] = ['serviceCode' => 'IB'];
        }

        return [
            'customerDetails' => [
                'shipperDetails' => $this->buildAddressDetails($from),
                'receiverDetails' => $this->buildAddressDetails($to),
            ],
            'accounts' => [['typeCode' => 'shipper', 'number' => $account['username_acc']]],
            'valueAddedServices' => $valueAddedServices,
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
            // Requesting this makes DHL quote back the actual insurance charge (as an "II"
            // line in detailedPriceBreakdown) instead of us estimating it. Only applies to the
            // non-document (box) declared value — documents use the flat 'IB' service above.
            ...($declaredValue > 0 ? [
                'monetaryAmount' => [[
                    'typeCode' => 'declaredValue',
                    'value' => $declaredValue,
                    'currency' => $shipment['declaredValueCurrency'] ?? 'THB',
                ]],
            ] : []),
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
        'II' => 'Declared Value (Insurance)',
        'IB' => 'Extended Liability (Document)',
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
                'volumetricWeight' => $product['weight']['volumetric'] ?? null,
                'isCustomerAgreement' => ($product['isCustomerAgreement'] ?? false) === true,
                'transitDays' => $product['deliveryCapabilities']['totalTransitDays'] ?? null,
                'estimatedDelivery' => $product['deliveryCapabilities']['estimatedDeliveryDateAndTime'] ?? null,
                'chargeBreakdown' => $chargeBreakdown,
                'raw' => $product,
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
                'volumetricWeight' => $q['volumetricWeight'],
                'published' => null,
                'negotiated' => $q['total'],
                'isCustomerAgreement' => $q['isCustomerAgreement'],
                'transitDays' => $q['transitDays'],
                'estimatedDelivery' => $q['estimatedDelivery'],
                'chargeBreakdown' => $q['chargeBreakdown'],
                'raw' => $q['raw'],
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
                // DHL always returns every product it has enabled ('productTypeCode' => 'all') —
                // filter down to this account's allowed product codes here, client-side, since
                // DHL has no per-request "only quote these products" filter (see
                // BranchCarrierAccount.allowed_service_codes; empty/missing = no restriction).
                $allowedServiceCodes = $account['allowed_service_codes'] ?? null;
                if ($allowedServiceCodes) {
                    $quotes = array_values(array_filter($quotes, fn ($q) => in_array($q['serviceCode'], $allowedServiceCodes, true)));
                }
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
                        'volumetricWeight' => $q['volumetricWeight'],
                        'published' => null,
                        'negotiated' => $q['total'],
                        'isCustomerAgreement' => $q['isCustomerAgreement'],
                        'transitDays' => $q['transitDays'],
                        'estimatedDelivery' => $q['estimatedDelivery'],
                        'chargeBreakdown' => $q['chargeBreakdown'],
                        'raw' => $q['raw'],
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

    /**
     * Lists every DHL Express product (code + real name) this account has enabled, so admins
     * configuring BranchCarrierAccount.allowed_service_codes know what a product code actually
     * means instead of guessing — DHL has no fixed product list like UPS does, and it varies
     * per account/route, so this reuses a lightweight reference /rates call (Thailand -> Singapore,
     * a small box) with 'productTypeCode' => 'all' and just returns the product codes/names,
     * ignoring price (unlike quoteAccounts, which filters out $0 "not really available" products).
     */
    public function listAvailableProducts(array $account): array
    {
        $referenceShipment = [
            'from' => ['country' => 'TH', 'city' => 'Bangkok', 'postcode' => '10110', 'address' => '1 Reference Rd'],
            'to' => ['country' => 'SG', 'city' => 'Singapore', 'postcode' => '238874', 'address' => '1 Reference Rd'],
            'packages' => [['weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1, 'isDocument' => false]],
            'declaredValueCurrency' => 'THB',
        ];

        $messageRef = $this->generateMessageReference();
        $response = Http::withBasicAuth($account['basic_auth_username'], $account['basic_auth_password'])
            ->withHeaders([
                'x-version' => '3.2.0',
                'Message-Reference' => $messageRef,
                'Message-Reference-Date' => now()->toRfc7231String(),
            ])
            ->timeout(20)
            ->post($this->dhlUrl($account['mode'] ?? null) . '/rates', $this->buildRateRequest($account, $referenceShipment));

        if (! $response->successful()) {
            throw new \RuntimeException('DHL product list request failed: ' . ($response->json('detail') ?? $response->json('title') ?? $response->status()));
        }

        $products = [];
        foreach ($response->json('products') ?? [] as $product) {
            $code = $product['productCode'] ?? null;
            if ($code === null || isset($products[$code])) continue;
            $products[$code] = [
                'code' => $code,
                'name' => $product['productName'] ?? $product['localProductCode'] ?? $code,
            ];
        }

        return array_values($products);
    }
}
