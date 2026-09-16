<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Books an actual UPS shipment (creates the real shipment + label) via the UPS Shipping API's
 * POST /api/shipments/v2409/ship — same OAuth token flow as UpsRateService, but this call is NOT
 * idempotent: every successful request books a real shipment with UPS.
 */
class UpsShipmentService
{
    public function getAccessToken(string $clientId, string $clientSecret, ?string $mode = null): string
    {
        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->timeout(15)
            ->post($this->upsUrl('oauth_url', $mode), ['grant_type' => 'client_credentials']);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException('UPS OAuth failed: '.($response->json('error_description') ?? $response->status()));
        }

        return $response->json('access_token');
    }

    private function upsUrl(string $key, ?string $mode): string
    {
        $suffix = $mode === 'test' ? '_test' : '';

        return config("services.ups.{$key}{$suffix}");
    }

    private function buildAddress(array $addr): array
    {
        return array_filter([
            'AddressLine' => [$addr['address'] ?? ''],
            'City' => $addr['city'] ?? '',
            'StateProvinceCode' => $addr['stateCode'] ?? null,
            'PostalCode' => $addr['postcode'] ?? '',
            'CountryCode' => $addr['country'] ?? '',
        ], fn ($v) => $v !== null);
    }

    /**
     * $shipment packages carry a `useCarrierInsurance` flag (set by the caller from which
     * Insurance Add-on was actually sold) — only THOSE packages' declared value is sent to UPS
     * as PackageServiceOptions.DeclaredValue, so we're never charged/declared insurance for a
     * package where a third-party (UPSC) product was sold instead.
     */
    private function buildShipmentRequest(array $account, array $shipment): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];
        $hasNonDocument = collect($shipment['packages'])->contains(fn ($pkg) => empty($pkg['isDocument']));
        $isInternational = ($from['country'] ?? '') !== ($to['country'] ?? '');

        $shipmentNode = [
            'Description' => $shipment['description'] ?: 'General Merchandise',
            'Shipper' => [
                'Name' => $from['contactName'] ?: ($from['company'] ?: 'Shipper'),
                'AttentionName' => $from['contactName'] ?? null,
                'Phone' => ['Number' => $from['phone'] ?: '0000000000'],
                'ShipperNumber' => $account['username_acc'],
                'Address' => $this->buildAddress($from),
            ],
            'ShipTo' => [
                'Name' => $to['contactName'] ?: ($to['company'] ?: 'Receiver'),
                'AttentionName' => $to['contactName'] ?? null,
                'Phone' => ['Number' => $to['phone'] ?: '0000000000'],
                'Address' => $this->buildAddress($to),
            ],
            'ShipFrom' => [
                'Name' => $from['contactName'] ?: ($from['company'] ?: 'Shipper'),
                'AttentionName' => $from['contactName'] ?? null,
                'Phone' => ['Number' => $from['phone'] ?: '0000000000'],
                'Address' => $this->buildAddress($from),
            ],
            'PaymentInformation' => [
                'ShipmentCharge' => [
                    'Type' => '01',
                    'BillShipper' => ['AccountNumber' => $account['username_acc']],
                ],
            ],
            'Service' => ['Code' => (string) $shipment['serviceCode']],
            // A package ROW's `quantity` means N physical identical boxes — UPS has no
            // per-package "quantity" field, so each row is expanded into `quantity` separate
            // Package entries (each at the row's per-box weight), same as UpsRateService.
            'Package' => collect($shipment['packages'])->flatMap(function (array $pkg) use ($shipment) {
                $pkgIsDocument = (bool) ($pkg['isDocument'] ?? false);
                $pkgDeclaredValue = (float) ($pkg['declaredValue'] ?? 0);
                $useCarrierInsurance = ! empty($pkg['useCarrierInsurance']);
                $quantity = max((int) ($pkg['quantity'] ?? 1), 1);

                $packageEntry = array_merge([
                    'Packaging' => ['Code' => $pkgIsDocument ? '01' : '02'],
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
                ], $useCarrierInsurance && $pkgDeclaredValue > 0 && ! $pkgIsDocument ? [
                    // UPS does not cover Document shipments with its own Declared Value insurance
                    // at all — never send it for a document package (see UpsRateService).
                    'PackageServiceOptions' => [
                        'DeclaredValue' => [
                            'CurrencyCode' => $shipment['declaredValueCurrency'] ?? 'THB',
                            'MonetaryValue' => number_format($pkgDeclaredValue, 2, '.', ''),
                        ],
                    ],
                ] : []);

                return array_fill(0, $quantity, $packageEntry);
            })->all(),
        ];

        // International non-document shipments require an invoice/contents value declaration.
        if ($isInternational && $hasNonDocument) {
            $totalValue = collect($shipment['packages'])->sum(fn ($pkg) => (float) ($pkg['declaredValue'] ?? 0));
            $shipmentNode['InvoiceLineTotal'] = [
                'CurrencyCode' => $shipment['declaredValueCurrency'] ?? 'THB',
                'MonetaryValue' => (string) ($totalValue > 0 ? $totalValue : 1),
            ];
        }

        return [
            'ShipmentRequest' => [
                'Request' => [
                    'RequestOption' => 'nonvalidate',
                    'TransactionReference' => ['CustomerContext' => 'MADD Shipment'],
                ],
                'Shipment' => $shipmentNode,
                'LabelSpecification' => [
                    'LabelImageFormat' => ['Code' => 'GIF'],
                    'HTTPUserAgent' => 'Mozilla/4.5',
                ],
            ],
        ];
    }

    /**
     * @return array{trackingNumber:string,labelBase64:?string,labelFormat:?string,raw:array}
     */
    public function createShipment(string $token, array $account, array $shipment, ?string $mode = null): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->post($this->upsUrl('ship_url', $mode), $this->buildShipmentRequest($account, $shipment));

        if (! $response->successful()) {
            throw new \RuntimeException('UPS shipment creation failed: '.($response->json('response.errors.0.message') ?? $response->status()));
        }

        $raw = $response->json();
        $results = $raw['ShipmentResponse']['ShipmentResults'] ?? [];
        $packageResults = $results['PackageResults'] ?? [];
        $firstPackage = array_is_list($packageResults) ? ($packageResults[0] ?? null) : $packageResults;

        return [
            'trackingNumber' => $results['ShipmentIdentificationNumber'] ?? ($firstPackage['TrackingNumber'] ?? null),
            'labelBase64' => $firstPackage['ShippingLabel']['GraphicImage'] ?? null,
            'labelFormat' => $firstPackage['ShippingLabel']['ImageFormat']['Code'] ?? 'GIF',
            'raw' => $raw,
        ];
    }
}
