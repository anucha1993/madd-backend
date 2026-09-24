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
                // UPS schema requires ShipmentCharge as an array of objects (<= 3 items),
                // even though we only ever send one (Type 01 = Transportation, billed to shipper).
                'ShipmentCharge' => [
                    [
                        'Type' => '01',
                        'BillShipper' => ['AccountNumber' => $account['username_acc']],
                    ],
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

                // Package-level Optional Services (DeliveryConfirmation/DeliverToAddresseeOnly/
                // DirectDeliveryOnly) apply the SAME shipment-wide selection to every package —
                // combined with the Declared Value block into ONE PackageServiceOptions object,
                // since UPS only accepts a single such key per package.
                $packageServiceOptions = array_merge(
                    $useCarrierInsurance && $pkgDeclaredValue > 0 && ! $pkgIsDocument ? [
                        // UPS does not cover Document shipments with its own Declared Value
                        // insurance at all — never send it for a document package (see UpsRateService).
                        'DeclaredValue' => [
                            'CurrencyCode' => $shipment['declaredValueCurrency'] ?? 'THB',
                            'MonetaryValue' => number_format($pkgDeclaredValue, 2, '.', ''),
                        ],
                    ] : [],
                    $this->buildPackageOptionalServices($shipment['upsOptionalServiceCodes'] ?? []),
                );

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
                ], $packageServiceOptions !== [] ? ['PackageServiceOptions' => $packageServiceOptions] : []);

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

        // Shipment-level Optional Services (currently just Saturday Delivery) + the Commercial
        // Invoice (InternationalForms) — mandatory-ish for international non-document shipments
        // so UPS actually returns a printable Commercial Invoice document (Form), matching DHL's
        // auto-generated one.
        $shipmentServiceOptions = array_merge(
            $this->buildShipmentOptionalServices($shipment['upsOptionalServiceCodes'] ?? []),
            $isInternational && $hasNonDocument ? ['InternationalForms' => $this->buildInternationalForms($shipment, $shipment['declaredValueCurrency'] ?? 'THB')] : [],
        );
        if ($shipmentServiceOptions !== []) {
            $shipmentNode['ShipmentServiceOptions'] = $shipmentServiceOptions;
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

    /** Shipment-level UPS Optional Services — currently just Saturday Delivery. */
    private function buildShipmentOptionalServices(array $codes): array
    {
        return in_array('SATURDAY', $codes, true) ? ['SaturdayDeliveryIndicator' => ''] : [];
    }

    /**
     * Package-level UPS Optional Services. Signature options (DCIS1/2/3) are mutually exclusive
     * by nature (radio in the UI) — DeliveryConfirmation.DCISType: "1"=Delivery Confirmation,
     * "2"=Signature Required, "3"=Adult Signature Required.
     */
    private function buildPackageOptionalServices(array $codes): array
    {
        $options = [];
        $dcisType = match (true) {
            in_array('DCIS3', $codes, true) => '3',
            in_array('DCIS2', $codes, true) => '2',
            in_array('DCIS1', $codes, true) => '1',
            default => null,
        };
        if ($dcisType !== null) {
            $options['DeliveryConfirmation'] = ['DCISType' => $dcisType];
        }
        if (in_array('ADDRESSEE_ONLY', $codes, true)) {
            $options['DeliverToAddresseeOnlyIndicator'] = '';
        }
        if (in_array('DIRECT_ONLY', $codes, true)) {
            $options['DirectDeliveryOnlyIndicator'] = '';
        }

        return $options;
    }

    /**
     * Prefers the free-form Commercial Invoice line items (same shipment-level list used by
     * DhlShipmentService::buildExportDeclaration) when provided; falls back to one Product per
     * non-document package otherwise. UPS renders the actual Commercial Invoice PDF itself from
     * this data (returned as ShipmentResults.Form) — no separate document upload needed.
     */
    private function buildInternationalForms(array $shipment, string $currency): array
    {
        $from = $shipment['from'];
        $to = $shipment['to'];
        $invoiceLines = collect($shipment['invoiceLines'] ?? []);

        if ($invoiceLines->isNotEmpty()) {
            $products = $invoiceLines->map(fn ($line) => array_filter([
                'Description' => $line['description'] ?: 'General Merchandise',
                'Unit' => [
                    'Number' => (string) ($line['quantity'] ?? 1),
                    'UnitOfMeasurement' => ['Code' => 'PCS'],
                    'Value' => number_format(((float) ($line['unit_value'] ?? 0)) ?: 1, 2, '.', ''),
                ],
                'OriginCountryCode' => $line['country_of_origin'] ?: $from['country'],
                'CommodityCode' => ($line['hs_code'] ?? null) ?: null,
            ], fn ($v) => $v !== null))->values()->all();
        } else {
            $products = collect($shipment['packages'])->filter(fn ($pkg) => empty($pkg['isDocument']))->map(fn ($pkg) => [
                'Description' => $pkg['description'] ?: 'General Merchandise',
                'Unit' => [
                    'Number' => (string) ($pkg['quantity'] ?? 1),
                    'UnitOfMeasurement' => ['Code' => 'PCS'],
                    'Value' => number_format(((float) ($pkg['declaredValue'] ?? 0)) ?: 1, 2, '.', ''),
                ],
                'OriginCountryCode' => $from['country'],
            ])->values()->all();
        }

        $form = [
            'FormType' => '01', // Commercial Invoice
            'InvoiceNumber' => $shipment['refInvoiceNo'] ?: ('INV-'.now()->format('YmdHis')),
            'InvoiceDate' => now()->format('Ymd'),
            'ReasonForExport' => 'SALE',
            'CurrencyCode' => $currency,
            'Product' => $products,
            // UPS rejects the whole ShipmentServiceOptions.InternationalForms block with a
            // generic "Missing contact information" error unless a Contacts.SoldTo party is
            // present — confirmed empirically against UPS's test API (SoldTo requires a "Name"
            // key specifically; "CompanyName" alone is NOT accepted and errors with "Invalid or
            // missing sold to name"). Default the Sold To party to the ShipTo (receiver), since
            // this app doesn't currently collect a separate buyer/sold-to contact.
            'Contacts' => [
                'SoldTo' => array_filter([
                    'Name' => $to['contactName'] ?: ($to['company'] ?: 'Receiver'),
                    'AttentionName' => $to['contactName'] ?? null,
                    'Address' => $this->buildAddress($to),
                    'Phone' => ['Number' => $to['phone'] ?: '0000000000'],
                ], fn ($v) => $v !== null),
            ],
        ];

        // UPLOAD mode (see createShipment) — reference the staff file uploaded to UPS's
        // Paperless Document API, so UPS's returned Form is backed by the actual file, not just
        // our auto-built Product lines.
        if (! empty($shipment['uploadedInvoiceDocumentId'])) {
            $form['UserCreatedForm'] = ['DocumentID' => [$shipment['uploadedInvoiceDocumentId']]];
        }

        return $form;
    }

    /**
     * Uploads a staff-provided file to UPS's Paperless Document API (customs evidence for
     * UPLOAD-mode Commercial Invoice) and returns the DocumentID to reference from
     * InternationalForms.UserCreatedForm. Schema/headers/URL version match the ONE variant
     * confirmed live-working 2026-09-16 (real 200 + DocumentID) — v2 endpoint (not v3),
     * ShipperNumber/transId/transactionSrc as HEADERS (not body fields), and the file content
     * field named UserCreatedFormFile (not UserCreatedFormImage). Do not change any of these
     * without re-verifying live first — see madd-notes.md for the extensive prior investigation.
     */
    public function uploadPaperlessDocument(string $token, array $account, ?string $mode, string $base64Content, string $fileFormat): string
    {
        $response = Http::withToken($token)
            ->withHeaders([
                'ShipperNumber' => $account['username_acc'],
                'transId' => (string) \Illuminate\Support\Str::uuid(),
                'transactionSrc' => config('services.ups.transaction_src', 'testing'),
            ])
            ->timeout(30)
            ->post($this->upsUrl('paperless_document_url', $mode), [
                'UploadRequest' => [
                    'Request' => [
                        'TransactionReference' => ['CustomerContext' => 'MADD Commercial Invoice Upload'],
                    ],
                    'UserCreatedForm' => [
                        'UserCreatedFormFileName' => 'commercial-invoice.'.strtolower($fileFormat),
                        'UserCreatedFormFileFormat' => strtolower($fileFormat),
                        'UserCreatedFormDocumentType' => '001',
                        'UserCreatedFormFile' => $base64Content,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS paperless document upload failed: token expired');
            }
            throw new \RuntimeException('UPS paperless document upload failed: '.($response->json('response.errors.0.message') ?? $response->body() ?? $response->status()));
        }

        $documentId = $response->json('UploadResponse.FormsHistoryDocumentID.DocumentID.0')
            ?? $response->json('UploadResponse.FormsHistoryDocumentID.DocumentID')
            ?? $response->json('UploadResponse.FormsHistoryDocumentID.0.DocumentID');

        if (is_array($documentId)) {
            $documentId = $documentId[0] ?? null;
        }

        if (! $documentId) {
            throw new \RuntimeException('UPS paperless document upload succeeded but no DocumentID was returned: '.$response->body());
        }

        return $documentId;
    }

    /**
     * Links an already-uploaded Paperless Document (via uploadPaperlessDocument()) to a real,
     * just-booked shipment — this is what actually makes the shipment's label carry the
     * "EDI-IDIS" marking for customs. Schema confirmed live-working 2026-09-23 — like Upload,
     * ShipperNumber must be sent as BOTH a header AND a body field (UPS's own published schema
     * only documents the body field; omitting the header fails with "9590002 Missing or Invalid
     * Shipper Number" even though the body value is correct).
     */
    public function pushToImageRepository(string $token, array $account, ?string $mode, string $documentId, string $trackingNumber): void
    {
        $response = Http::withToken($token)
            ->withHeaders([
                'ShipperNumber' => $account['username_acc'],
                'transId' => (string) \Illuminate\Support\Str::uuid(),
                'transactionSrc' => config('services.ups.transaction_src', 'testing'),
            ])
            ->timeout(30)
            ->post($this->upsUrl('paperless_image_url', $mode), [
                'PushToImageRepositoryRequest' => [
                    'Request' => [
                        'TransactionReference' => ['CustomerContext' => 'MADD Commercial Invoice Push'],
                    ],
                    'ShipperNumber' => $account['username_acc'],
                    'FormsHistoryDocumentID' => ['DocumentID' => [$documentId]],
                    'ShipmentIdentifier' => $trackingNumber,
                    'ShipmentDateAndTime' => now()->format('Y-m-d-H.i.s'),
                    'ShipmentType' => '1',
                    'TrackingNumber' => [$trackingNumber],
                ],
            ]);

        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS push to image repository failed: token expired');
            }
            throw new \RuntimeException('UPS push to image repository failed: '.($response->json('response.errors.0.message') ?? $response->body() ?? $response->status()));
        }
    }

    /**
     * @return array{trackingNumber:string,labelBase64:?string,labelFormat:?string,waybillBase64:?string,waybillFormat:?string,commercialInvoiceBase64:?string,commercialInvoiceFormat:?string,pieces:array<int,array{trackingNumber:?string,labelBase64:?string,labelFormat:?string}>,raw:array}
     */
    public function createShipment(string $token, array $account, array $shipment, ?string $mode = null): array
    {
        if (! empty($shipment['uploadedInvoice'])) {
            // Best-effort: UPS's Paperless Document upload is a separate, currently-unreliable
            // API (see madd-notes.md) — a failure here must NOT block the actual booking. The
            // uploaded file still gets merged into our own stored Commercial Invoice PDF
            // (ShipmentController::buildCombinedCommercialInvoiceStorageKey) regardless of
            // whether UPS accepted it via this endpoint.
            try {
                $shipment['uploadedInvoiceDocumentId'] = $this->uploadPaperlessDocument(
                    $token,
                    $account,
                    $mode,
                    $shipment['uploadedInvoice']['content'],
                    $shipment['uploadedInvoice']['format'],
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

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

        $parsed = $this->parseShipmentResponse($raw);

        // Best-effort, same reasoning as the Upload call above: link the uploaded document to the
        // shipment we just booked so it actually shows the "EDI-IDIS" marking for customs, but
        // never let a failure here undo an otherwise-successful booking.
        if (! empty($shipment['uploadedInvoiceDocumentId']) && ! empty($parsed['trackingNumber'])) {
            try {
                $this->pushToImageRepository($token, $account, $mode, $shipment['uploadedInvoiceDocumentId'], $parsed['trackingNumber']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $parsed;
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

        // Form (Commercial Invoice) is only present when the request included
        // ShipmentServiceOptions.InternationalForms (see buildInternationalForms()) — now always
        // requested for international non-document shipments. Unlike ShippingLabel/
        // ControlLogReceipt, UPS nests Form's actual image bytes one level deeper under an
        // "Image" object (Form.Image.GraphicImage / Form.Image.ImageFormat), NOT directly on
        // Form itself — confirmed live against the test API.
        $form = $results['Form']['Image'] ?? null;

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
