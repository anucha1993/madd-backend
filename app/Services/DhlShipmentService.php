<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Books an actual DHL shipment (creates the real air waybill + label) via MyDHL API's
 * POST /shipments — same account credentials/base URL as DhlRateService, but this call is NOT
 * idempotent: every successful request books a real shipment with DHL.
 */
class DhlShipmentService
{
    private function generateMessageReference(): string
    {
        return 'madd-'.now()->timestamp.'-'.bin2hex(random_bytes(4));
    }

    private function dhlUrl(?string $mode): string
    {
        return $mode === 'test' ? config('services.dhl.api_url_test') : config('services.dhl.api_url');
    }

    private function buildPostalAddress(array $addr): array
    {
        return [
            'postalCode' => $addr['postcode'] ?? '',
            'cityName' => $addr['city'] ?? '',
            'countryCode' => $addr['country'] ?? '',
            'addressLine1' => $addr['address'] ?? '',
            'addressLine2' => $addr['address2'] ?? null,
            'addressLine3' => $addr['address3'] ?? null,
        ];
    }

    private function buildContactInformation(array $addr): array
    {
        return [
            'phone' => $addr['phone'] ?: '0000000000',
            'companyName' => $addr['company'] ?: ($addr['contactName'] ?: 'N/A'),
            'fullName' => $addr['contactName'] ?: ($addr['company'] ?: 'N/A'),
            'email' => $addr['email'] ?? null,
        ];
    }

    /**
     * $shipment packages carry a `useCarrierInsurance` flag (set by the caller from which
     * Insurance Add-on was actually sold) — only THOSE packages' declared value is sent to DHL.
     * Boxes use 'II' (value-based Insurance); documents use 'IB' (Extended Liability — a flat
     * lump-sum service, no value/currency) since DHL prices document protection completely
     * differently (see DhlRateService for the reasoning) — never for a package where a
     * third-party (UPSC) product was sold instead.
     */
    private function buildShipmentRequest(array $account, array $shipment): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];
        $packages = $shipment['packages'];

        $hasNonDocumentPackage = collect($packages)->contains(fn ($pkg) => empty($pkg['isDocument']));
        $insuredPackages = collect($packages)->filter(fn ($pkg) => ! empty($pkg['useCarrierInsurance']));
        $declaredValue = $insuredPackages
            ->filter(fn ($pkg) => empty($pkg['isDocument']))
            ->sum(fn ($pkg) => (float) ($pkg['declaredValue'] ?? 0));
        $hasInsuredDocument = $insuredPackages
            ->contains(fn ($pkg) => ! empty($pkg['isDocument']) && (float) ($pkg['declaredValue'] ?? 0) > 0);
        $currency = $shipment['declaredValueCurrency'] ?? 'THB';

        $expandedPackages = [];
        foreach ($packages as $pkg) {
            for ($i = 0; $i < (int) ($pkg['quantity'] ?? 1); $i++) {
                $expandedPackages[] = [
                    'weight' => (float) $pkg['weight'],
                    // Document packages never collect dims in the UI — same standard envelope
                    // fallback as DhlRateService, so the real booking matches what was quoted.
                    'dimensions' => [
                        'length' => (float) ($pkg['length'] ?? 35),
                        'width' => (float) ($pkg['width'] ?? 25),
                        'height' => (float) ($pkg['height'] ?? 2),
                    ],
                ];
            }
        }

        return [
            'plannedShippingDateAndTime' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i:s \G\M\TP'),
            'pickup' => ['isRequested' => false],
            'productCode' => $shipment['serviceCode'],
            'accounts' => [['typeCode' => 'shipper', 'number' => $account['username_acc']]],
            'outputImageProperties' => [
                'printerDPI' => 300,
                'encodingFormat' => 'pdf',
                'imageOptions' => [['typeCode' => 'label', 'templateName' => 'ECOM26_84_A4_001', 'isRequested' => true]],
            ],
            'customerDetails' => [
                'shipperDetails' => [
                    'postalAddress' => $this->buildPostalAddress($from),
                    'contactInformation' => $this->buildContactInformation($from),
                ],
                'receiverDetails' => [
                    'postalAddress' => $this->buildPostalAddress($to),
                    'contactInformation' => $this->buildContactInformation($to),
                ],
            ],
            'content' => [
                'packages' => $expandedPackages,
                'isCustomsDeclarable' => $hasNonDocumentPackage && $from['country'] !== $to['country'],
                'declaredValue' => $declaredValue > 0 ? $declaredValue : null,
                'declaredValueCurrency' => $currency,
                // DHL has no literal "isDocument" flag anywhere in this schema (confirmed
                // against DHL's own Rating/Shipment example payloads) — "Documents" here is
                // just the conventional description text DHL's own examples use, no functional
                // effect on pricing/customs (that's driven entirely by isCustomsDeclarable above).
                'description' => $shipment['description'] ?: (! $hasNonDocumentPackage ? 'Documents' : 'General Merchandise'),
                'incoterm' => 'DAP',
                'unitOfMeasurement' => 'metric',
            ],
            'valueAddedServices' => array_merge(
                $declaredValue > 0 ? [['serviceCode' => 'II', 'value' => $declaredValue, 'currency' => $currency]] : [],
                $hasInsuredDocument ? [['serviceCode' => 'IB']] : [],
            ),
        ];
    }

    /**
     * @return array{trackingNumber:string,labelBase64:?string,labelFormat:?string,raw:array}
     */
    public function createShipment(array $account, array $shipment): array
    {
        $messageRef = $this->generateMessageReference();

        $response = Http::withBasicAuth($account['basic_auth_username'], $account['basic_auth_password'])
            ->withHeaders([
                'x-version' => '3.2.0',
                'Message-Reference' => $messageRef,
                'Message-Reference-Date' => now()->toRfc7231String(),
            ])
            ->timeout(30)
            ->post($this->dhlUrl($account['mode'] ?? null).'/shipments', $this->buildShipmentRequest($account, $shipment));

        if (! $response->successful()) {
            throw new \RuntimeException('DHL shipment creation failed: '.($response->json('detail') ?? $response->json('title') ?? $response->status()));
        }

        $raw = $response->json();
        $labelDoc = collect($raw['documents'] ?? [])->firstWhere('typeCode', 'label');

        return [
            'trackingNumber' => $raw['shipmentTrackingNumber'] ?? null,
            'labelBase64' => $labelDoc['content'] ?? null,
            'labelFormat' => $labelDoc['imageFormat'] ?? 'PDF',
            'raw' => $raw,
        ];
    }
}
