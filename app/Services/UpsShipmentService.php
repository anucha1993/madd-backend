<?php

namespace App\Services;

use App\Exceptions\UpsTokenExpiredException;
use App\Services\Concerns\HasUpsOAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * Books an actual UPS shipment (creates the real shipment + label) via the UPS Shipping API's
 * POST /api/shipments/v2409/ship — same OAuth token flow as UpsRateService, but this call is NOT
 * idempotent: every successful request books a real shipment with UPS.
 */
class UpsShipmentService
{
    use HasUpsOAuthToken;

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
                $useCarrierInsurance = ! empty($pkg['useCarrierInsurance']);
                $quantity = max((int) ($pkg['quantity'] ?? 1), 1);
                // `declaredValue` is always the TOTAL for this row (all `quantity` boxes
                // combined, see packageTotalDeclaredValue() in shipment/create/page.tsx) —
                // divide across each expanded box so UPS's shipment-wide total (summed
                // per-package) matches what staff entered, instead of multiplying by quantity.
                $pkgDeclaredValue = (float) ($pkg['declaredValue'] ?? 0) / $quantity;

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
     * @return array{trackingNumber:string,labelBase64:?string,labelFormat:?string,waybillBase64:?string,waybillFormat:?string,commercialInvoiceBase64:?string,commercialInvoiceFormat:?string,pieces:array<int,array{trackingNumber:?string,labelBase64:?string,labelFormat:?string}>,raw:array}
     */
    public function createShipment(string $token, array $account, array $shipment, ?string $mode = null): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->post($this->upsUrl('ship_url', $mode), $this->buildShipmentRequest($account, $shipment));

        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS shipment creation failed: token expired');
            }
            throw new \RuntimeException('UPS shipment creation failed: '.($response->json('response.errors.0.message') ?? $response->status()));
        }

        $raw = $response->json();

        return $this->parseShipmentResponse($raw);
    }

    /**
     * Pulls tracking/label/waybill/invoice/pieces out of a raw UPS Shipment API response —
     * shared by createShipment() (fresh booking) and the shipments:backfill-documents command
     * (re-parsing an already-saved raw_response for shipments booked before waybill/invoice
     * capture existed, without re-booking anything with UPS).
     */
    public function parseShipmentResponse(array $raw): array
    {
        $results = $raw['ShipmentResponse']['ShipmentResults'] ?? [];
        $packageResults = $results['PackageResults'] ?? [];
        // UPS returns ONE PackageResults entry per physical box for a multi-piece shipment
        // (single ShipmentRequest, `Package` array) — a single-package shipment collapses this
        // to one object instead of a list, so normalize both shapes into a flat list here.
        // `array_is_list()` throws on non-array input, so guard with `is_array()` first — some
        // older raw_response payloads (re-parsed by the backfill command) may lack this key
        // entirely, leaving it `null` rather than `[]`.
        $packageResultsList = is_array($packageResults) && array_is_list($packageResults) ? $packageResults : ($packageResults ? [$packageResults] : []);
        $firstPackage = $packageResultsList[0] ?? null;

        // ControlLogReceipt is the "Shipper's Copy" waybill/receipt — one per shipment (not per
        // piece), kept as proof of booking rather than stuck on any box. Same shape quirk as
        // PackageResults (single object vs list) when only one is returned.
        $controlLogReceipt = $results['ControlLogReceipt'] ?? null;
        $controlLogReceiptList = is_array($controlLogReceipt) && array_is_list($controlLogReceipt) ? $controlLogReceipt : ($controlLogReceipt ? [$controlLogReceipt] : []);
        $waybill = $controlLogReceiptList[0] ?? null;

        // Form (Commercial Invoice) is only returned when the request includes
        // ShipmentServiceOptions.InternationalForms — we don't request it yet, so this is
        // normally absent, but parsed defensively in case it's ever added upstream.
        $form = $results['Form'] ?? null;

        return [
            'trackingNumber' => $results['ShipmentIdentificationNumber'] ?? ($firstPackage['TrackingNumber'] ?? null),
            'labelBase64' => $firstPackage['ShippingLabel']['GraphicImage'] ?? null,
            'labelFormat' => $firstPackage['ShippingLabel']['ImageFormat']['Code'] ?? 'GIF',
            'waybillBase64' => $waybill['GraphicImage'] ?? null,
            'waybillFormat' => $waybill['ImageFormat']['Code'] ?? 'GIF',
            'commercialInvoiceBase64' => $form['GraphicImage'] ?? null,
            'commercialInvoiceFormat' => $form['ImageFormat']['Code'] ?? 'GIF',
            'pieces' => array_map(fn ($pkg) => [
                'trackingNumber' => $pkg['TrackingNumber'] ?? null,
                'labelBase64' => $pkg['ShippingLabel']['GraphicImage'] ?? null,
                'labelFormat' => $pkg['ShippingLabel']['ImageFormat']['Code'] ?? 'GIF',
            ], $packageResultsList),
            'raw' => $raw,
        ];
    }

    /**
     * UPS's Label Recovery API (POST /labels/v1/recovery) — re-fetches the label (and, per its
     * documented schema, a Form/commercial-invoice) for an ALREADY-BOOKED shipment by tracking
     * number. CONFIRMED against UPS's own published schema: `LabelRecoveryResponse` only exposes
     * `Response, ShipmentIdentificationNumber, LabelResults, CODTurnInPage, Form,
     * HighValueReport, TrackingCandidate` at the top level — there is NO `ControlLogReceipt`
     * field in this response at all (unlike the Ship response's `ShipmentResults
     * .ControlLogReceipt`). So `waybillBase64` below will realistically never be populated via
     * this endpoint, no matter the tracking number/account — kept only for defensive parsing in
     * case some account/shipment variant does expose it nested under `LabelResults[]`. This
     * endpoint's own doc description is also scoped to "the return shipment", i.e. its primary
     * intended use is UPS Returns, not recovering a lost waybill for a normal forward shipment.
     *
     * @return array{labelBase64:?string,labelFormat:?string,waybillBase64:?string,waybillFormat:?string,commercialInvoiceBase64:?string,commercialInvoiceFormat:?string,raw:array}
     */
    public function recoverDocuments(string $token, string $trackingNumber, ?string $mode = null): array
    {
        $response = Http::withToken($token)
            ->timeout(20)
            ->post($this->upsUrl('recovery_url', $mode), [
                'LabelRecoveryRequest' => [
                    'Request' => ['TransactionReference' => ['CustomerContext' => 'MADD Document Recovery']],
                    'TrackingNumber' => $trackingNumber,
                    'LabelSpecification' => ['LabelImageFormat' => ['Code' => 'GIF']],
                ],
            ]);

        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS label recovery failed: token expired');
            }
            throw new \RuntimeException('UPS label recovery failed: '.($response->json('response.errors.0.message') ?? $response->status()));
        }

        $raw = $response->json();
        $labelResults = $raw['LabelRecoveryResponse']['LabelResults'] ?? [];
        // Same single-object-vs-list shape quirk as ShipmentResults.PackageResults.
        $labelResultsList = is_array($labelResults) && array_is_list($labelResults) ? $labelResults : ($labelResults ? [$labelResults] : []);
        $first = $labelResultsList[0] ?? [];

        $controlLogReceipt = $first['ControlLogReceipt'] ?? null;
        // UPS's Form nesting differs slightly between the Ship and Recovery responses in some
        // accounts (GraphicImage directly vs nested under Image) — check both defensively.
        $form = $first['Form'] ?? null;
        $formImage = $form['GraphicImage'] ?? $form['Image']['GraphicImage'] ?? null;
        $formFormat = $form['ImageFormat']['Code'] ?? $form['Image']['ImageFormat']['Code'] ?? 'GIF';

        return [
            'labelBase64' => $first['ShippingLabel']['GraphicImage'] ?? null,
            'labelFormat' => $first['ShippingLabel']['ImageFormat']['Code'] ?? 'GIF',
            'waybillBase64' => $controlLogReceipt['GraphicImage'] ?? null,
            'waybillFormat' => $controlLogReceipt['ImageFormat']['Code'] ?? 'GIF',
            'commercialInvoiceBase64' => $formImage,
            'commercialInvoiceFormat' => $formFormat,
            'raw' => $raw,
        ];
    }

    /**
     * UPS's Void Shipment API (DELETE /shipments/{version}/void/cancel/{trackingNumber}) — a
     * REAL cancellation of the air waybill with UPS, not just a local status flag. Only works
     * within UPS's own "allowed void period" (roughly: before the shipment is picked up/scanned,
     * and generally within ~28 days of creation) — UPS returns error code 190102 ("No shipment
     * found within the allowed void period") once that window has passed, which the caller
     * should surface to the user rather than silently failing.
     */
    public function voidShipment(string $token, string $trackingNumber, ?string $mode = null): array
    {
        $response = Http::withToken($token)
            ->timeout(20)
            ->delete($this->upsUrl('void_url', $mode).'/'.$trackingNumber);

        $raw = $response->json();
        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS void failed: token expired');
            }
            throw new \RuntimeException('UPS void failed: '.($raw['response']['errors'][0]['message'] ?? $response->status()));
        }

        return $raw;
    }
}
