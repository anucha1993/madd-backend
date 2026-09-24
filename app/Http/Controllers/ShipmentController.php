<?php

namespace App\Http\Controllers;

use App\Exceptions\UpsTokenExpiredException;
use App\Models\AddonItem;
use App\Models\AgentAccount;
use App\Models\Branch;
use App\Models\BranchCarrierAccount;
use App\Models\Shipment;
use App\Services\DhlShipmentService;
use App\Services\R2Service;
use App\Services\UpsShipmentService;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class ShipmentController extends Controller
{
    public function __construct(
        private UpsShipmentService $upsShipmentService,
        private DhlShipmentService $dhlShipmentService,
        private R2Service $r2Service,
    ) {
    }

    /**
     * Fetches a (possibly cached) UPS token and calls $fn($token) — if UPS responds 401 because
     * a cached token died before its TTL, forces a fresh token and retries $fn ONCE more.
     */
    private function withUpsToken(AgentAccount $account, callable $fn)
    {
        $token = $this->upsShipmentService->getAccessToken($account->client_id, $account->client_secret, $account->mode);
        try {
            return $fn($token);
        } catch (UpsTokenExpiredException $e) {
            $token = $this->upsShipmentService->getAccessToken($account->client_id, $account->client_secret, $account->mode, true);

            return $fn($token);
        }
    }

    private const LABEL_MIME_TYPES = [
        'PDF' => 'application/pdf',
        'GIF' => 'image/gif',
        'ZPL' => 'application/octet-stream',
    ];

    /**
     * Which Branch "booked" this shipment — drives the header info on any Receipt/Tax Invoice
     * issued for it later. Prefers a branch that's actually configured to use the chosen agent
     * account (see BranchCarrierAccount) when the staff member belongs to more than one branch,
     * else falls back to their first branch. Null if the user has no branch at all.
     */
    private function resolveBranchId(Request $request, int $agentAccountId): ?int
    {
        $userBranchIds = $request->user()?->branches()->pluck('branches.id') ?? collect();
        if ($userBranchIds->isEmpty()) {
            // No branch assigned to this user at all — if the whole system only has ONE branch
            // configured, default to it rather than leaving branch_id null (which silently makes
            // the shipment permanently ineligible for Receipt/Tax Invoice issuance later).
            return Branch::count() === 1 ? Branch::value('id') : null;
        }

        $matching = BranchCarrierAccount::where('agent_account_id', $agentAccountId)
            ->whereIn('branch_id', $userBranchIds)
            ->value('branch_id');

        return $matching ?? $userBranchIds->first();
    }

    /**
     * Lists booked/failed shipments for the "My Shipments" page — newest first, with optional
     * search (tracking number) / carrier / status filters.
     */
    public function index(Request $request)
    {
        // Only eager-load ACTIVE (status='requested') Pickups — lets the frontend disable
        // re-selecting a Shipment that's already scheduled on an outstanding Pickup (see
        // PickupController::store()'s matching server-side guard). `receipts_count` similarly
        // lets the frontend disable a Shipment already attached to any Receipt/Tax Invoice
        // (see receipt_shipment's global lock — voided receipts still count).
        $query = Shipment::with(['agentAccount.agent', 'branch', 'pickups' => fn ($q) => $q->where('status', 'requested')])
            ->withCount('receipts')
            ->latest();

        if ($search = $request->query('search')) {
            // Matches tracking number as well as sender/receiver name/company/phone (stored in
            // the origin/destination JSON columns) — lets the Issue Receipt picker (and this
            // page's own search) find a shipment by who it's from/to, not just its tracking no.
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('tracking_number', 'like', $like)
                    ->orWhere('origin->contact_name', 'like', $like)
                    ->orWhere('origin->company', 'like', $like)
                    ->orWhere('destination->contact_name', 'like', $like)
                    ->orWhere('destination->company', 'like', $like)
                    ->orWhere('destination->phone', 'like', $like);
            });
        }
        if ($carrier = $request->query('carrier')) {
            $query->where('carrier', $carrier);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($customerType = $request->query('customer_type')) {
            $query->where('customer_type', $customerType);
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        // For the Issue Receipt/Tax Invoice picker — only shipments never attached to any
        // Receipt yet (see receipt_shipment's global unique-per-shipment lock).
        if ($request->boolean('unbilled')) {
            $query->whereDoesntHave('receipts');
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Summary KPI numbers for the "My Shipments" page header (merged former /dashboard page) —
     * today/this-month counts, this-month revenue, and current in-transit/cancelled snapshots.
     * `booked` is the only status counted as a "real" shipment for count/revenue purposes
     * (pending/failed attempts never actually shipped anything).
     */
    public function stats(Request $request)
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        return response()->json([
            'today_count' => Shipment::where('status', 'booked')->where('created_at', '>=', $today)->count(),
            'month_count' => Shipment::where('status', 'booked')->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
            'month_revenue' => (float) Shipment::where('status', 'booked')->whereBetween('created_at', [$monthStart, $monthEnd])->sum('order_total'),
            'in_transit_count' => Shipment::where('status', 'booked')->count(),
            'cancelled_count' => Shipment::whereIn('status', ['voided', 'failed'])->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
        ]);
    }

    /**
     * Books a REAL shipment with UPS or DHL from the quote the staff selected (see
     * ShippingController::checkRate) — every successful call here creates an actual air waybill
     * with the carrier and is NOT reversible from our side.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_account_id' => ['required', 'integer'],
            'carrier' => ['required', 'string', 'in:UPS,DHL'],
            'service_code' => ['required', 'string'],
            'service_label' => ['nullable', 'string'],

            'origin' => ['required', 'array'],
            'origin.contact_name' => ['nullable', 'string', 'max:255'],
            'origin.company' => ['nullable', 'string', 'max:255'],
            'origin.tax_id' => ['nullable', 'string', 'max:50'],
            'origin.postcode' => ['required', 'string', 'max:10'],
            'origin.city' => ['required', 'string', 'max:255'],
            'origin.address' => ['required', 'string', 'max:1000'],
            'origin.address2' => ['nullable', 'string', 'max:1000'],
            'origin.address3' => ['nullable', 'string', 'max:1000'],
            'origin.phone' => ['nullable', 'string', 'max:50'],
            'origin.notes' => ['nullable', 'string', 'max:1000'],

            'destination' => ['required', 'array'],
            'destination.contact_name' => ['nullable', 'string', 'max:255'],
            'destination.company' => ['nullable', 'string', 'max:255'],
            'destination.tax_id' => ['nullable', 'string', 'max:50'],
            'destination.country' => ['required', 'string', 'size:2', 'not_in:TH'],
            'destination.city' => ['required', 'string', 'max:255'],
            'destination.state' => ['nullable', 'string', 'max:10'],
            'destination.postcode' => ['nullable', 'string', 'max:20'],
            'destination.address' => ['nullable', 'string', 'max:1000'],
            'destination.address2' => ['nullable', 'string', 'max:1000'],
            'destination.address3' => ['nullable', 'string', 'max:1000'],
            'destination.phone' => ['nullable', 'string', 'max:50'],
            'destination.email' => ['nullable', 'email', 'max:255'],
            'destination.notes' => ['nullable', 'string', 'max:1000'],

            'packages' => ['required', 'array', 'min:1'],
            'packages.*.weight' => ['required', 'numeric', 'min:0.01'],
            'packages.*.is_document' => ['boolean'],
            'packages.*.length' => ['required_if:packages.*.is_document,false', 'nullable', 'numeric', 'min:1'],
            'packages.*.width' => ['required_if:packages.*.is_document,false', 'nullable', 'numeric', 'min:1'],
            'packages.*.height' => ['required_if:packages.*.is_document,false', 'nullable', 'numeric', 'min:1'],
            'packages.*.quantity' => ['nullable', 'integer', 'min:1'],
            'packages.*.description' => ['nullable', 'string', 'max:500'],
            'packages.*.declared_value' => ['nullable', 'numeric', 'min:0'],
            'packages.*.insured' => ['nullable', 'boolean'],
            'packages.*.product_type' => ['nullable', 'string', 'max:50'],
            'packages.*.product_type_other' => ['nullable', 'string', 'max:255'],
            // Which Insurance Add-on (if any) was actually sold for this package — used ONLY to
            // decide whether declared_value is reported to the carrier as ITS OWN insurance
            // service (never for third-party UPSC, see DhlShipmentService/UpsShipmentService).
            'packages.*.insurance_addon_item_id' => ['nullable', 'integer'],
            'declared_value_currency' => ['nullable', 'string', 'size:3'],

            // Commercial Invoice step — free-form product lines (NOT tied 1:1 to physical
            // packages, a real invoice usually lists products not boxes), used to build DHL's
            // mandatory exportDeclaration.lineItems for EVERY customs-declarable shipment.
            // `invoice_mode` is kept only for backward compatibility (defaults to FORM); the
            // uploaded file is now a SEPARATE optional supplementary attachment (documentImages
            // typeCode CIN on DHL) and does NOT replace the line items — see the DHL MyDHL+
            // portal for reference: even the "Upload" mode there only accepts structured
            // CSV/TXT/XML product data, PDFs/images are explicitly not allowed.
            'invoice_mode' => ['nullable', 'in:FORM,UPLOAD'],
            'invoice_lines' => ['required', 'array', 'min:1'],
            'invoice_lines.*.description' => ['required', 'string', 'max:500'],
            'invoice_lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'invoice_lines.*.unit_value' => ['required', 'numeric', 'min:0'],
            'invoice_lines.*.weight' => ['nullable', 'numeric', 'min:0'],
            'invoice_lines.*.country_of_origin' => ['nullable', 'string', 'max:2'],
            'invoice_lines.*.hs_code' => ['nullable', 'string', 'max:20'],
            // Storage key from POST /shipments/upload-commercial-invoice — sent to the carrier
            // as a supplementary document (DHL documentImages CIN) IN ADDITION to invoice_lines,
            // not as a replacement.
            'commercial_invoice_upload_key' => ['nullable', 'string', 'max:255'],
            // DHL Optional Services (live-verified against DHL's own /reference-data serviceCode
            // dataset) — applied to both the rate check and the real booking so quoted/charged
            // amounts stay consistent.
            'dhl_optional_services' => ['nullable', 'array'],
            'dhl_optional_services.*' => ['string', 'in:FD,LX,NN,SD,SF,SX,SG,WL,WM'],
            // UPS Optional Services — same codes/behaviour as ShippingController::checkRate.
            'ups_optional_services' => ['nullable', 'array'],
            'ups_optional_services.*' => ['string', 'in:SATURDAY,DCIS1,DCIS2,DCIS3,ADDRESSEE_ONLY,DIRECT_ONLY'],

            'addon_lines' => ['nullable', 'array'],
            'addon_lines.*.name' => ['required', 'string'],
            'addon_lines.*.category' => ['nullable', 'string'],
            'addon_lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'addon_lines.*.unit_price' => ['required', 'numeric'],

            'freight_amount' => ['required', 'numeric'],
            'addon_total' => ['required', 'numeric'],
            'order_total' => ['required', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],

            // Step 1 (Customer Type / Individual Category) and Step 4 (Payment Info) fields —
            // never actually used by the carrier APIs, but must still be saved for the record.
            'customer_type' => ['nullable', 'string', 'max:50'],
            'entity_type' => ['nullable', 'string', 'in:INDIVIDUAL,COMPANY'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'bill_transportation_to' => ['nullable', 'string', 'max:50'],
            'bill_duty_tax_to' => ['nullable', 'string', 'max:50'],
            'ref_invoice_no' => ['nullable', 'string', 'max:255'],
            'ref_insurance_no' => ['nullable', 'string', 'max:255'],
            'ref_purchase_no' => ['nullable', 'string', 'max:255'],
            // The full Rate Quote card the staff picked (zone, transit days, billed weight,
            // chargeBreakdown, raw carrier response, etc.) — service_code/service_label alone
            // don't capture everything that was actually shown/selected at Check Rate.
            'rate_quote' => ['nullable', 'array'],
        ]);


        $account = AgentAccount::with('agent')->where('status', true)->find($data['agent_account_id']);
        if (! $account || $account->agent?->agent_code !== $data['carrier']) {
            return response()->json(['error' => 'ไม่พบบัญชี Carrier ที่เลือกไว้ หรือบัญชีถูกปิดใช้งานแล้ว'], 400);
        }

        // Resolve each package's insurance product ONCE so both the carrier request and the
        // saved record agree on which packages are actually using the carrier's own insurance.
        $insuranceItemIds = collect($data['packages'])->pluck('insurance_addon_item_id')->filter()->unique();
        $insuranceItemsById = AddonItem::whereIn('id', $insuranceItemIds)->get()->keyBy('id');

        $packages = array_map(function ($pkg) use ($insuranceItemsById) {
            $insuranceItem = isset($pkg['insurance_addon_item_id']) ? $insuranceItemsById->get($pkg['insurance_addon_item_id']) : null;

            return [
                'weight' => $pkg['weight'],
                'length' => $pkg['length'] ?? null,
                'width' => $pkg['width'] ?? null,
                'height' => $pkg['height'] ?? null,
                'quantity' => $pkg['quantity'] ?? 1,
                'isDocument' => (bool) ($pkg['is_document'] ?? false),
                'declaredValue' => $pkg['declared_value'] ?? null,
                'useCarrierInsurance' => $insuranceItem?->price_type === 'API_COST',
                // Only actually used by DhlShipmentService to build the mandatory
                // content.exportDeclaration.lineItems block for customs-declarable shipments.
                'description' => $pkg['description'] ?? null,
                'productType' => $pkg['product_type'] === 'OTHER' ? ($pkg['product_type_other'] ?? null) : ($pkg['product_type'] ?? null),
            ];
        }, $data['packages']);

        $shipment = [
            'from' => [
                'contactName' => $data['origin']['contact_name'] ?? null,
                'company' => $data['origin']['company'] ?? null,
                'country' => 'TH',
                'city' => $data['origin']['city'],
                'postcode' => $data['origin']['postcode'],
                'address' => $data['origin']['address'],
                'address2' => $data['origin']['address2'] ?? null,
                'address3' => $data['origin']['address3'] ?? null,
                'phone' => $data['origin']['phone'] ?? null,
            ],
            'to' => [
                'contactName' => $data['destination']['contact_name'] ?? null,
                'company' => $data['destination']['company'] ?? null,
                'country' => strtoupper($data['destination']['country']),
                'city' => $data['destination']['city'],
                'stateCode' => $data['destination']['state'] ?? null,
                'postcode' => $data['destination']['postcode'] ?? '',
                'address' => $data['destination']['address'] ?? '',
                'address2' => $data['destination']['address2'] ?? null,
                'address3' => $data['destination']['address3'] ?? null,
                'phone' => $data['destination']['phone'] ?? null,
                'email' => $data['destination']['email'] ?? null,
            ],
            'packages' => $packages,
            'declaredValueCurrency' => $data['declared_value_currency'] ?? 'THB',
            'serviceCode' => $data['service_code'],
            'refInvoiceNo' => $data['ref_invoice_no'] ?? null,
            'description' => $data['packages'][0]['description'] ?? null,
            // ALWAYS the primary source for DhlShipmentService::buildExportDeclaration —
            // DHL mandates lineItems on every customs-declarable shipment (confirmed against
            // MyDHL+ portal). A supplementary file upload (see below) does NOT replace this.
            'invoiceLines' => $data['invoice_lines'] ?? [],
            'optionalServiceCodes' => $data['dhl_optional_services'] ?? ['SF'],
            'upsOptionalServiceCodes' => $data['ups_optional_services'] ?? [],
        ];

        // Supplementary Commercial Invoice attachment — attached to the carrier request as
        // documentImages typeCode CIN (DHL) IN ADDITION to invoiceLines, not as a replacement.
        // Attached whenever the storage key is present, regardless of invoice_mode.
        if (! empty($data['commercial_invoice_upload_key'])) {
            $uploadKey = $data['commercial_invoice_upload_key'];
            $ext = strtoupper(pathinfo($uploadKey, PATHINFO_EXTENSION));
            $shipment['uploadedInvoice'] = [
                'content' => base64_encode($this->r2Service->download($uploadKey)),
                'format' => in_array($ext, ['JPG', 'JPEG']) ? 'JPEG' : ($ext === 'PNG' ? 'PNG' : 'PDF'),
            ];
        }

        $recordAttributes = [
            'agent_account_id' => $account->id,
            'branch_id' => $this->resolveBranchId($request, $account->id),
            'created_by' => $request->user()?->id,
            'carrier' => $data['carrier'],
            'service_code' => $data['service_code'],
            'service_label' => $data['service_label'] ?? null,
            'origin' => $data['origin'],
            'destination' => $data['destination'],
            'packages' => $data['packages'],
            'addon_lines' => $data['addon_lines'] ?? [],
            'invoice_mode' => $data['invoice_mode'] ?? 'FORM',
            'invoice_lines' => $data['invoice_lines'] ?? [],
            'freight_amount' => $data['freight_amount'],
            'addon_total' => $data['addon_total'],
            'order_total' => $data['order_total'],
            'currency' => $data['currency'] ?? 'THB',
            'customer_type' => $data['customer_type'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'bill_transportation_to' => $data['bill_transportation_to'] ?? null,
            'bill_duty_tax_to' => $data['bill_duty_tax_to'] ?? null,
            'ref_invoice_no' => $data['ref_invoice_no'] ?? null,
            'ref_insurance_no' => $data['ref_insurance_no'] ?? null,
            'ref_purchase_no' => $data['ref_purchase_no'] ?? null,
            'rate_quote' => $data['rate_quote'] ?? null,
        ];

        try {
            if ($data['carrier'] === 'DHL') {
                $result = $this->dhlShipmentService->createShipment([
                    'id' => $account->id,
                    'username_acc' => $account->username_acc,
                    'basic_auth_username' => $account->basic_auth_username,
                    'basic_auth_password' => $account->basic_auth_password,
                    'mode' => $account->mode,
                ], $shipment);
            } else {
                $result = $this->withUpsToken($account, fn ($token) => $this->upsShipmentService->createShipment($token, [
                    'id' => $account->id,
                    'username_acc' => $account->username_acc,
                ], $shipment, $account->mode));
            }
        } catch (\Throwable $e) {
            $record = Shipment::create($recordAttributes + [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage(), 'shipment_id' => $record->id], 422);
        }

        // UPS returns a separate label per physical piece (PackageResults), so each piece's
        // label is uploaded to R2 individually. DHL returns one combined PDF covering every
        // piece as separate pages, so all DHL pieces just point at that one uploaded label.
        $pieces = $result['pieces'] ?? [];
        if ($data['carrier'] === 'UPS') {
            $pieceRecords = array_map(
                fn ($piece) => [
                    'tracking_number' => $piece['trackingNumber'],
                    'label_storage_key' => $this->uploadShipmentLabel($piece['trackingNumber'], $piece['labelBase64'] ?? null, $piece['labelFormat'] ?? null),
                ],
                $pieces,
            );
            $labelStorageKey = $pieceRecords[0]['label_storage_key'] ?? null;
        } else {
            $labelStorageKey = $this->uploadShipmentLabel($result['trackingNumber'], $result['labelBase64'] ?? null, $result['labelFormat'] ?? null);
            $pieceRecords = array_map(
                fn ($piece) => ['tracking_number' => $piece['trackingNumber'], 'label_storage_key' => $labelStorageKey],
                $pieces,
            );
        }

        $record = Shipment::create($recordAttributes + [
            'status' => 'booked',
            'tracking_number' => $result['trackingNumber'],
            'pieces' => $pieceRecords,
            'label_storage_key' => $labelStorageKey,
            'waybill_storage_key' => $this->uploadShipmentDocument('waybill', $result['trackingNumber'], $result['waybillBase64'] ?? null, $result['waybillFormat'] ?? null),
            // Page 1 = the carrier's own invoice (from the Shipment API response), page 2+ = the
            // staff-uploaded invoice file, if any — merged into one PDF for both UPS and DHL.
            'commercial_invoice_storage_key' => $this->buildCombinedCommercialInvoiceStorageKey(
                $result['trackingNumber'],
                $result['commercialInvoiceBase64'] ?? null,
                $result['commercialInvoiceFormat'] ?? null,
                $shipment['uploadedInvoice'] ?? null,
            ),
            'raw_response' => $result['raw'],
        ]);

        return response()->json($record);
    }

    /**
     * Uploads a staff-provided Commercial Invoice file (Commercial Invoice step's Upload option)
     * BEFORE booking — the returned key is passed back as `commercial_invoice_upload_key` in the
     * main store() request so it can be attached to the Shipment record once created. Max size
     * capped at 5MB (not the more common 10MB) to match DHL's own stated "Upload Your Customs
     * Documents" limit (5MB total per shipment) — a larger file would pass this check but then
     * fail at DHL's own API with a much less clear error.
     */
    public function uploadCommercialInvoiceFile(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $file = $request->file('file');
        $upload = $this->r2Service->upload(
            'manual-'.$file->getClientOriginalName(),
            $file->get(),
            $file->getMimeType() ?: 'application/octet-stream',
        );

        return response()->json(['storage_key' => $upload['key']]);
    }

    /**
     * Label upload failing must not lose an already-booked real shipment — the tracking
     * number/raw response are still saved, the label can be re-fetched/re-uploaded later.
     */
    private function uploadShipmentLabel(?string $trackingNumber, ?string $labelBase64, ?string $labelFormat): ?string
    {
        return $this->uploadShipmentDocument('shipment', $trackingNumber, $labelBase64, $labelFormat);
    }

    /**
     * Shared upload helper for every carrier-returned document (label, waybill, commercial
     * invoice) — same "never lose the already-booked shipment over an upload hiccup" behavior.
     */
    private function uploadShipmentDocument(string $prefix, ?string $trackingNumber, ?string $base64, ?string $format): ?string
    {
        if (! $base64) {
            return null;
        }

        try {
            $mime = self::LABEL_MIME_TYPES[$format] ?? 'application/octet-stream';
            $extension = strtolower($format ?? 'bin');
            $upload = $this->r2Service->upload("{$prefix}-{$trackingNumber}.{$extension}", base64_decode($base64), $mime);

            return $upload['key'];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Builds the final Commercial Invoice document for a booked shipment: page 1 is the
     * carrier's own invoice (from the Shipment API response, e.g. UPS Form.Image.GraphicImage),
     * followed by every page of the staff-uploaded invoice file, if one was attached — same
     * merge behavior for both UPS and DHL. Falls back to the old passthrough (native format,
     * no PDF conversion) when there's no staff upload to merge, to avoid changing existing
     * single-file behavior unnecessarily.
     */
    private function buildCombinedCommercialInvoiceStorageKey(?string $trackingNumber, ?string $carrierBase64, ?string $carrierFormat, ?array $uploadedInvoice): ?string
    {
        if (! $uploadedInvoice) {
            return $this->uploadShipmentDocument('invoice', $trackingNumber, $carrierBase64, $carrierFormat);
        }

        $mpdf = new Mpdf(['format' => 'A4']);
        $tempPaths = [];
        $addedAnyPage = false;

        try {
            if ($carrierBase64) {
                $addedAnyPage = $this->appendDocumentPagesToMpdf($mpdf, $carrierBase64, $carrierFormat, $tempPaths) || $addedAnyPage;
            }
            $addedAnyPage = $this->appendDocumentPagesToMpdf($mpdf, $uploadedInvoice['content'], $uploadedInvoice['format'], $tempPaths) || $addedAnyPage;

            if (! $addedAnyPage) {
                return null;
            }

            $merged = $mpdf->Output('', 'S');

            return $this->uploadShipmentDocument('invoice', $trackingNumber, base64_encode($merged), 'PDF');
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            foreach ($tempPaths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Appends every page of a base64-encoded document to $mpdf — every page of a PDF, or one
     * full page for an image (GIF/PNG/JPEG, converted to truecolor RGB first since UPS's own
     * GIFs are palette-mode and mpdf's Image() silently drops those). Returns true if at least
     * one page was added. Temp file paths are collected into &$tempPaths for the caller to clean up.
     */
    private function appendDocumentPagesToMpdf(Mpdf $mpdf, string $base64Content, ?string $format, array &$tempPaths): bool
    {
        $extension = strtolower($format ?? 'pdf');
        $tempPath = tempnam(sys_get_temp_dir(), 'invoice-merge-').'.'.$extension;
        file_put_contents($tempPath, base64_decode($base64Content));
        $tempPaths[] = $tempPath;

        if ($extension === 'pdf') {
            $pageCount = $mpdf->setSourceFile($tempPath);
            for ($page = 1; $page <= $pageCount; $page++) {
                $templateId = $mpdf->importPage($page);
                $size = $mpdf->getTemplateSize($templateId);
                $mpdf->AddPageByArray([
                    'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                    'sheet-size' => [$size['width'], $size['height']],
                    'margin-top' => 0,
                    'margin-bottom' => 0,
                    'margin-left' => 0,
                    'margin-right' => 0,
                ]);
                $mpdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);
            }

            return $pageCount > 0;
        }

        $src = @imagecreatefromstring(file_get_contents($tempPath));
        if (! $src) {
            return false;
        }
        $rgb = imagecreatetruecolor(imagesx($src), imagesy($src));
        imagefilledrectangle($rgb, 0, 0, imagesx($src), imagesy($src), imagecolorallocate($rgb, 255, 255, 255));
        imagecopy($rgb, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
        imagedestroy($src);
        $pngPath = $tempPath.'.png';
        imagepng($rgb, $pngPath);
        imagedestroy($rgb);
        $tempPaths[] = $pngPath;

        $mpdf->AddPage();
        $dataUri = 'data:image/png;base64,'.base64_encode(file_get_contents($pngPath));
        $mpdf->WriteHTML('<div style="text-align:center;"><img src="'.$dataUri.'" style="max-width:190mm;max-height:270mm;"></div>');

        return true;
    }

    /**
     * Full detail of one booked/failed shipment — read-only view of everything that was entered
     * at /shipment/create (used by the "View Details" action on /shipment/list).
     */
    public function show(Shipment $shipment)
    {
        return $shipment->load('agentAccount.agent', 'createdBy');
    }

    /**
     * Permanently deletes a Shipment — only ever allowed for a Test-mode booking (real production
     * bookings must use void() instead, they can never be deleted). Must have no Receipt/Tax
     * Invoice attached (delete that first — see ReceiptController::destroy()).
     */
    public function destroy(Shipment $shipment)
    {
        $shipment->loadMissing('agentAccount');
        if (! $shipment->is_test) {
            return response()->json(['error' => 'ลบได้เฉพาะ Shipment ที่จองด้วย Agent Account โหมด Test เท่านั้น — Shipment จริงให้ใช้ Void แทน'], 403);
        }
        if ($shipment->receipts()->exists()) {
            return response()->json(['error' => 'Shipment นี้ถูกออกใบเสร็จ/ใบกำกับภาษีไปแล้ว กรุณาลบเอกสารนั้นก่อน'], 422);
        }

        $shipment->delete();

        return response()->noContent();
    }

    /**
     * Cancels a booked shipment. UPS: a REAL cancellation with UPS via the Void Shipment API
     * (live-verified request/response shape — only fails once past UPS's "allowed void period",
     * which is surfaced back to the caller as an error). DHL: DHL Express's API has NO
     * shipment-cancel endpoint at all (confirmed against DHL's own published OpenAPI spec — the
     * only DELETE operation in the entire spec is for cancelling a Pickup request, not a
     * Shipment) — so for DHL this only ever flips our own local status, it never contacts DHL.
     * Only allowed while `status === 'booked'` (can't void something already voided/failed).
     */
    public function void(Shipment $shipment)
    {
        if ($shipment->status !== 'booked') {
            return response()->json(['error' => 'Shipment นี้ไม่ได้อยู่ในสถานะ booked จึงยกเลิกไม่ได้'], 422);
        }

        if ($shipment->carrier === 'UPS') {
            $shipment->loadMissing('agentAccount');
            $account = $shipment->agentAccount;
            if (! $account) {
                return response()->json(['error' => 'ไม่พบบัญชี UPS ที่ใช้จอง shipment นี้'], 400);
            }

            try {
                $this->withUpsToken($account, fn ($token) => $this->upsShipmentService->voidShipment($token, $shipment->tracking_number, $account->mode));
            } catch (\Throwable $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            $shipment->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_note' => 'Cancelled with UPS via Void Shipment API',
            ]);
        } else {
            // DHL: no carrier API to call — this is a local-only record, staff must still
            // contact DHL directly (or simply not tender the package) to actually stop it.
            $shipment->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_note' => 'Marked cancelled in MADD only — DHL Express has no shipment-cancel API; contact DHL directly to stop this shipment.',
            ]);
        }

        return $shipment->load('agentAccount.agent', 'createdBy');
    }

    /**
     * Streams the label file back through OUR OWN auth — the R2 bucket is never made public
     * (see R2Service::download). Served `inline` (not `attachment`) with its real content type
     * so the frontend can open it directly in a new tab, ready to print, instead of forcing a save-to-disk dialog.
     * Pass `?tracking_number=` to fetch a specific piece's label on a multi-piece UPS shipment
     * instead of the shipment's default/master label.
     */
    public function label(Request $request, Shipment $shipment)
    {
        $trackingNumber = $request->query('tracking_number');
        $storageKey = $shipment->label_storage_key;
        $pieces = collect($shipment->pieces ?? []);
        $pieceIndex = null;

        if ($trackingNumber) {
            $pieceIndex = $pieces->search(fn ($p) => ($p['tracking_number'] ?? null) === $trackingNumber);
            if ($pieceIndex === false) {
                return response()->json(['error' => 'ไม่พบ tracking number นี้ในรายการ shipment'], 404);
            }
            $storageKey = $pieces[$pieceIndex]['label_storage_key'] ?? null;
        }

        if (! $storageKey) {
            $recoveredKey = $this->recoverUpsDocument($shipment, $trackingNumber ?? $shipment->tracking_number, 'label');
            if ($recoveredKey) {
                $storageKey = $recoveredKey;
                if ($pieceIndex !== null) {
                    $updatedPieces = $pieces->all();
                    $updatedPieces[$pieceIndex]['label_storage_key'] = $recoveredKey;
                    $shipment->update(['pieces' => $updatedPieces]);
                } else {
                    $shipment->update(['label_storage_key' => $recoveredKey]);
                }
            }
        }

        // DHL only ever returns ONE combined PDF covering every piece as separate pages (see
        // DhlShipmentService) — every piece's label_storage_key points at that same file. So a
        // specific piece's "Label" click must extract JUST that piece's page, otherwise it's
        // indistinguishable from clicking any other piece (always opens the full multi-page PDF).
        if ($shipment->carrier === 'DHL' && $pieceIndex !== null && $pieces->count() > 1 && $storageKey) {
            return $this->streamSingleDhlLabelPage($storageKey, $pieceIndex + 1, $trackingNumber);
        }

        return $this->streamDocument($storageKey, 'shipment-'.($trackingNumber ?? $shipment->tracking_number), 'label');
    }

    /**
     * Merges EVERY piece's own label page into ONE multi-page PDF and streams it as a single
     * document — lets "Print All Labels" open/print in one native PDF viewer tab instead of
     * stacking a separate mini-viewer iframe per piece (which looked broken/glitchy: repeated
     * toolbars, inconsistent print() across iframes). Handles both carriers uniformly: DHL
     * pieces share one combined source file (imports a different page per piece); UPS pieces
     * each have their own dedicated file (page 1 of each). Non-PDF label files (e.g. UPS GIF)
     * are drawn in as a full-page image instead of importing a PDF page.
     */
    public function allLabels(Shipment $shipment)
    {
        $pieces = collect($shipment->pieces ?? [])->filter(fn ($p) => ! empty($p['label_storage_key']))->values();
        if ($pieces->isEmpty()) {
            return $this->label(request(), $shipment);
        }

        $mpdf = new Mpdf(['format' => 'A4']);
        $tempPaths = [];
        // How many pieces before this one already used the SAME storage key — gives the
        // correct 1-based page number within a shared multi-piece source file (DHL).
        $seenPerKey = [];

        foreach ($pieces as $piece) {
            $storageKey = $piece['label_storage_key'];
            $seenPerKey[$storageKey] = ($seenPerKey[$storageKey] ?? 0) + 1;
            $pageNumber = $seenPerKey[$storageKey];

            if (! isset($tempPaths[$storageKey])) {
                $bytes = $this->r2Service->download($storageKey);
                $extension = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION)) ?: 'pdf';
                $tempPath = tempnam(sys_get_temp_dir(), 'label-').'.'.$extension;
                file_put_contents($tempPath, $bytes);
                $tempPaths[$storageKey] = $tempPath;
            }
            $path = $tempPaths[$storageKey];

            try {
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
                    $pageCount = $mpdf->setSourceFile($path);
                    $templateId = $mpdf->importPage(min($pageNumber, $pageCount));
                    $size = $mpdf->getTemplateSize($templateId);
                    // Size THIS page to the imported template's own dimensions (mm) instead of a
                    // fixed A4 canvas — otherwise a compact/thermal-size label gets padded onto
                    // a much bigger blank page.
                    $mpdf->AddPageByArray([
                        'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                        'sheet-size' => [$size['width'], $size['height']],
                    ]);
                    $mpdf->useTemplate($templateId);
                } else {
                    // UPS labels are palette-mode GIFs that mpdf's Image() silently drops —
                    // convert to a truecolor RGB PNG first (same trick as buildUpsDiyWaybill).
                    // Also rotate 90° CW so the label reads portrait on A4 instead of sideways.
                    $src = @imagecreatefromstring(file_get_contents($path));
                    if (! $src) {
                        continue;
                    }
                    if (imagesx($src) > imagesy($src)) {
                        $rotated = imagerotate($src, 270, 0);
                        imagedestroy($src);
                        $src = $rotated;
                    }
                    $rgb = imagecreatetruecolor(imagesx($src), imagesy($src));
                    imagefilledrectangle($rgb, 0, 0, imagesx($src), imagesy($src), imagecolorallocate($rgb, 255, 255, 255));
                    imagecopy($rgb, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                    imagedestroy($src);
                    $rgbPath = $path.'.rgb.png';
                    imagepng($rgb, $rgbPath);
                    imagedestroy($rgb);
                    $tempPaths[$storageKey.'.rgb'] = $rgbPath;

                    $mpdf->AddPage();
                    $dataUri = 'data:image/png;base64,'.base64_encode(file_get_contents($rgbPath));
                    $mpdf->WriteHTML('<div style="text-align:center;"><img src="'.$dataUri.'" style="max-width:190mm;max-height:270mm;"></div>');
                }
            } catch (\Throwable $e) {
                // Skip a piece that fails to import rather than aborting the whole merged PDF.
                continue;
            }
        }

        $merged = $mpdf->Output('', 'S');
        foreach ($tempPaths as $path) {
            unlink($path);
        }

        return response($merged, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="shipment-'.$shipment->tracking_number.'-all-labels.pdf"',
        ]);
    }

    /**
     * Extracts page $pageNumber (1-based) out of DHL's combined multi-piece label PDF and
     * streams just that single page — uses mpdf's bundled FPDI import (setSourceFile/
     * importPage/useTemplate), no extra dependency needed. Falls back to the full combined PDF
     * if extraction fails for any reason (e.g. an unexpected page count mismatch).
     */
    private function streamSingleDhlLabelPage(string $storageKey, int $pageNumber, ?string $trackingNumber)
    {
        $bytes = $this->r2Service->download($storageKey);
        $tempPath = tempnam(sys_get_temp_dir(), 'dhl-label-').'.pdf';
        file_put_contents($tempPath, $bytes);

        try {
            $mpdf = new Mpdf(['format' => 'A4']);
            $pageCount = $mpdf->setSourceFile($tempPath);
            if ($pageNumber > $pageCount) {
                throw new \RuntimeException("page {$pageNumber} out of range ({$pageCount} total)");
            }
            $templateId = $mpdf->importPage($pageNumber);
            $size = $mpdf->getTemplateSize($templateId);
            // Size THIS page to the imported template's own dimensions (mm) instead of a fixed
            // A4 canvas — otherwise a compact/thermal-size label gets padded onto a much bigger
            // blank page.
            $mpdf->AddPageByArray([
                'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                'sheet-size' => [$size['width'], $size['height']],
            ]);
            $mpdf->useTemplate($templateId);
            $single = $mpdf->Output('', 'S');
        } catch (\Throwable $e) {
            unlink($tempPath);

            return $this->streamDocument($storageKey, 'shipment-'.($trackingNumber ?? 'label'), 'label');
        }

        unlink($tempPath);

        return response($single, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="shipment-'.($trackingNumber ?? 'label').'.pdf"',
        ]);
    }

    /**
     * UPS's "Shipper's Copy" waybill/receipt (ControlLogReceipt) — proof the shipment was
     * booked, kept separate from the label(s) actually stuck on the boxes. Neither carrier's
     * API reliably returns one for a TH-origin account (DHL never had it; UPS's
     * ControlLogReceipt comes back empty for non-US shippers), so a stand-in A4 PDF is built
     * in-house per carrier — see buildDhlDiyWaybill() and buildUpsDiyWaybill().
     */
    public function waybill(Shipment $shipment)
    {
        $pdf = $shipment->carrier === 'UPS'
            ? $this->buildUpsDiyWaybill($shipment)
            : $this->buildDhlDiyWaybill($shipment);

        if (! $pdf) {
            return $this->streamDocument(null, 'waybill-'.$shipment->tracking_number, 'waybill');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="waybill-'.$shipment->tracking_number.'.pdf"',
        ]);
    }

    /**
     * DHL has no carrier-issued Waybill/Shipper's-Copy document (unlike UPS), so this builds a
     * stand-in in-house: imports the FIRST page of the shipment's own label (piece #1's page —
     * already carries piece #1's own tracking barcode) onto a slightly taller sheet, then writes
     * the OTHER pieces' tracking numbers underneath, in ONE horizontal line (piece #1's number
     * is already on the label itself, no need to repeat it). Single-piece shipments get just the
     * label page back with no extra list. Returns raw PDF bytes, or null if no label to build from.
     */
    private function buildDhlDiyWaybill(Shipment $shipment): ?string
    {
        $storageKey = $shipment->label_storage_key;
        if (! $storageKey) {
            return null;
        }

        $otherTrackingNumbers = collect($shipment->pieces ?? [])
            ->pluck('tracking_number')
            ->filter()
            ->skip(1)
            ->values();

        $bytes = $this->r2Service->download($storageKey);
        $extension = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION)) ?: 'pdf';
        $tempPath = tempnam(sys_get_temp_dir(), 'dhl-waybill-').'.'.$extension;
        file_put_contents($tempPath, $bytes);

        try {
            $mpdf = new Mpdf(['format' => 'A4']);
            $extraHeight = $otherTrackingNumbers->isEmpty() ? 0 : 20;

            if ($extension === 'pdf') {
                $mpdf->setSourceFile($tempPath);
                $templateId = $mpdf->importPage(1);
                $size = $mpdf->getTemplateSize($templateId);
                $mpdf->AddPageByArray([
                    'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                    'sheet-size' => [$size['width'], $size['height'] + $extraHeight],
                    'margin-top' => 0,
                    'margin-bottom' => 0,
                    'margin-left' => 0,
                    'margin-right' => 0,
                ]);
                $mpdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);
                $labelBottomY = $size['height'];
            } else {
                // Non-PDF label (e.g. image) — draw it in at a fixed size, same as allLabels().
                $sheetWidth = 190;
                $labelHeight = 270;
                $mpdf->AddPageByArray([
                    'sheet-size' => [$sheetWidth + 20, $labelHeight + 20 + $extraHeight],
                    'margin-top' => 0,
                    'margin-bottom' => 0,
                    'margin-left' => 0,
                    'margin-right' => 0,
                ]);
                $mpdf->Image($tempPath, 10, 10, $sheetWidth, 0, '', '', false, false);
                $labelBottomY = $labelHeight + 10;
            }

            if ($otherTrackingNumbers->isNotEmpty()) {
                // Renumbered 1..N for the DISPLAYED list only (piece #1 is intentionally excluded
                // — its number is already printed on the label above).
                $line = ' '.$otherTrackingNumbers
                    ->map(fn ($trackingNumber, $i) => ($i + 1).'. '.$trackingNumber)
                    ->implode('   ');
                // Text() writes at an exact fixed position and never triggers mpdf's automatic
                // page-break (unlike Write()) — needed since this sits right at the sheet's
                // bottom edge, past where Write() would otherwise overflow onto a new page.
                $mpdf->SetFont('', 'B', 9);
                $mpdf->Text(5, $labelBottomY + 9, 'Tracking Numbers');
                $mpdf->SetFont('', '', 9);
                $mpdf->Text(5, $labelBottomY + 15, $line);
            }

            return $mpdf->Output('', 'S');
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            unlink($tempPath);
        }
    }

    /**
     * UPS doesn't return a usable ControlLogReceipt for TH-origin accounts (empirically empty
     * from both Ship and Label Recovery API), so this builds a stand-in on A4: takes the UPS
     * label GIF (stored landscape/rotated for thermal printing), converts it to a truecolor PNG
     * and rotates 90° CW so it reads portrait, pastes it centered near the top, and lists the
     * OTHER pieces' tracking numbers underneath in ONE horizontal line (piece #1's number is
     * already printed on the label itself, no need to repeat it). HTML+data URI is used instead
     * of $mpdf->Image() — mpdf's Image() silently drops rotated GD resources on this GIF variant.
     * Returns raw PDF bytes, or null if the shipment has no label to build from.
     */
    private function buildUpsDiyWaybill(Shipment $shipment): ?string
    {
        $storageKey = $shipment->label_storage_key;
        if (! $storageKey) {
            return null;
        }

        // Piece order mirrors the flattened Package array UPS was booked with (each package
        // row's `quantity` expanded into that many individual boxes) — zip pieces with the same
        // expansion so each tracking number can show ITS OWN box's real size/weight.
        $otherPieces = collect($shipment->pieces ?? [])->filter(fn ($p) => ! empty($p['tracking_number']))->skip(1)->values();
        $otherPackages = collect($shipment->packages ?? [])
            ->flatMap(fn ($pkg) => array_fill(0, max((int) ($pkg['quantity'] ?? 1), 1), $pkg))
            ->skip(1)
            ->values();

        $bytes = $this->r2Service->download($storageKey);
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return null;
        }
        // imagerotate() uses positive-angle = CCW; the stored landscape label reads
        // "sideways-up" so a 90° CCW ends up upside-down — need 90° CW (270).
        if (imagesx($src) > imagesy($src)) {
            $rotated = imagerotate($src, 270, 0);
            imagedestroy($src);
            $src = $rotated;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $rgb = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($rgb, 255, 255, 255);
        imagefilledrectangle($rgb, 0, 0, $w, $h, $white);
        imagecopy($rgb, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);
        $tempPng = tempnam(sys_get_temp_dir(), 'ups-waybill-').'.png';
        imagepng($rgb, $tempPng);
        imagedestroy($rgb);

        try {
            $mpdf = new Mpdf(['format' => 'A4', 'margin_top' => 10, 'margin_bottom' => 10, 'margin_left' => 10, 'margin_right' => 10, 'tempDir' => sys_get_temp_dir()]);
            $dataUri = 'data:image/png;base64,'.base64_encode(file_get_contents($tempPng));

            $trackingHtml = '';
            if ($otherPieces->isNotEmpty()) {
                // Renumbered 1..N for the DISPLAYED list only (piece #1 is intentionally excluded
                // — its number is already printed on the label above).
                $line = $otherPieces
                    ->map(function ($piece, $i) use ($otherPackages) {
                        $pkg = $otherPackages->get($i);
                        $detail = '';
                        if ($pkg) {
                            $dims = collect([$pkg['length'] ?? null, $pkg['width'] ?? null, $pkg['height'] ?? null])
                                ->filter()
                                ->implode('x');
                            $detailParts = array_filter([
                                $dims !== '' ? $dims.'cm' : null,
                                isset($pkg['weight']) ? $pkg['weight'].'kg' : null,
                            ]);
                            if ($detailParts) {
                                $detail = ' ('.implode(', ', $detailParts).')';
                            }
                        }

                        return ($i + 1).'. '.htmlspecialchars((string) $piece['tracking_number']).htmlspecialchars($detail);
                    })
                    ->implode('<br>');
                $trackingHtml = '<div style="font-weight:bold;">Tracking Numbers</div>'
                    .'<div>'.$line.'</div>';
            }

            // Label kept LEFT (its own narrower column so it never grows into the right column)
            // — the right column stacks PAYMENT OF CHARGES, then Tracking Numbers, then TOTAL
            // CHARGES (the price billed to the customer) LAST at the very bottom, well clear of
            // the label's own barcode/text area instead of both being crammed below a full-width
            // label like before.
            $rightColumnHtml = implode('<div style="height:6mm;"></div>', array_filter([
                $this->buildPaymentOfChargesHtml($shipment),
                $trackingHtml,
                $this->buildTotalChargesHtml($shipment),
            ]));

            $bodyHtml = '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
                .'<td style="width:52%;vertical-align:top;"><img src="'.$dataUri.'" style="max-width:88mm;max-height:250mm;"></td>'
                .'<td style="width:48%;vertical-align:top;padding-left:6mm;font-family:sans-serif;font-size:10pt;">'.$rightColumnHtml.'</td>'
                .'</tr></table>';
            $mpdf->WriteHTML($bodyHtml);

            return $mpdf->Output('', 'S');
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            unlink($tempPng);
        }
    }

    /**
     * "PAYMENT OF CHARGES" block on a real UPS Shipper's Copy — only the options that actually
     * apply to this shipment are printed, no unchecked alternatives listed (each label still
     * pulled from the real Shipment record: bill_transportation_to / bill_duty_tax_to, and the
     * REAL UPS shipper account number that booked it via agentAccount->username_acc). PRE is
     * always the real state here since this app only ever books via an account (see
     * UpsShipmentService::buildShipmentRequest — PaymentInformation always sends BillShipper,
     * never a Collect charge type).
     */
    private function buildPaymentOfChargesHtml(Shipment $shipment): string
    {
        $billTransportTo = strtoupper($shipment->bill_transportation_to ?: 'SHIPPER');
        $billDutyTaxTo = strtoupper($shipment->bill_duty_tax_to ?: 'RECEIVER');
        $shipperAccountNumber = $shipment->agentAccount?->username_acc;

        $transportLabel = match ($billTransportTo) {
            'RECEIVER' => 'Bill Transportation to Receiver',
            'THIRD_PARTY' => 'Bill Transportation to Third Party',
            default => 'Bill Transportation to Shipper'.($shipperAccountNumber ? ' '.$shipperAccountNumber : ''),
        };
        $dutyTaxLabel = match ($billDutyTaxTo) {
            'SHIPPER' => 'Bill Duty and Tax to Shipper'.($shipperAccountNumber ? ' '.$shipperAccountNumber : ''),
            'THIRD_PARTY' => 'Bill Duty and Tax to Third Party',
            default => 'Bill Duty and Tax to Receiver',
        };

        return '<div style="font-weight:bold;">PAYMENT OF CHARGES</div>'
            .'<div>[X] PRE</div>'
            .'<div>[X] '.htmlspecialchars($transportLabel).'</div>'
            .'<div>[X] '.htmlspecialchars($dutyTaxLabel).'</div>';
    }

    /**
     * TOTAL CHARGES — the actual price billed to the customer for this shipment (freight +
     * addons), same order_total staff entered on the Payment Info step, not a UPS-quoted rate.
     */
    private function buildTotalChargesHtml(Shipment $shipment): string
    {
        $currency = $shipment->currency ?: 'THB';

        return '<div style="font-weight:bold;">TOTAL CHARGES</div>'
            .'<div>'.htmlspecialchars($currency).' '.number_format((float) $shipment->order_total, 2).'</div>';
    }

    /**
     * Commercial Invoice for customs — DHL auto-generates it for customs-declarable shipments;
     * UPS returns one whenever InternationalForms was requested (see
     * UpsShipmentService::buildInternationalForms, now sent for every international
     * non-document shipment).
     */
    public function commercialInvoice(Shipment $shipment)
    {
        $storageKey = $shipment->commercial_invoice_storage_key;
        if (! $storageKey) {
            $recoveredKey = $this->recoverUpsDocument($shipment, $shipment->tracking_number, 'invoice');
            if ($recoveredKey) {
                $storageKey = $recoveredKey;
                $shipment->update(['commercial_invoice_storage_key' => $recoveredKey]);
            }
        }

        return $this->streamDocument($storageKey, 'invoice-'.$shipment->tracking_number, 'ใบกำกับสินค้าศุลกากร (Commercial Invoice)');
    }

    /**
     * Fallback when a document was never saved locally (upload failed, or booked before we
     * captured it) — calls UPS's Label Recovery API to re-fetch whatever UPS actually generated
     * for that tracking number, uploads it to R2, and returns the new storage key. Returns null
     * (falls through to the normal 404) for DHL: DHL's create-shipment response already embeds
     * every document in full, so `raw_response` (saved on every shipment) always has everything
     * — recovering it is just `php artisan shipments:backfill-documents`, no live carrier call
     * needed. UPS is different: some documents (e.g. ControlLogReceipt) may never have been in
     * our saved raw_response at all, so only a live Recovery call can still produce them.
     */
    private function recoverUpsDocument(Shipment $shipment, ?string $trackingNumber, string $kind): ?string
    {
        if ($shipment->carrier !== 'UPS' || ! $trackingNumber) {
            return null;
        }

        $shipment->loadMissing('agentAccount');
        $account = $shipment->agentAccount;
        if (! $account) {
            return null;
        }

        try {
            $recovered = $this->withUpsToken($account, fn ($token) => $this->upsShipmentService->recoverDocuments($token, $trackingNumber, $account->mode));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return match ($kind) {
            'label' => $this->uploadShipmentDocument('shipment', $trackingNumber, $recovered['labelBase64'] ?? null, $recovered['labelFormat'] ?? null),
            'waybill' => $this->uploadShipmentDocument('waybill', $trackingNumber, $recovered['waybillBase64'] ?? null, $recovered['waybillFormat'] ?? null),
            'invoice' => $this->uploadShipmentDocument('invoice', $trackingNumber, $recovered['commercialInvoiceBase64'] ?? null, $recovered['commercialInvoiceFormat'] ?? null),
            default => null,
        };
    }

    private function streamDocument(?string $storageKey, string $filenamePrefix, string $notFoundLabel)
    {
        if (! $storageKey) {
            return response()->json(['error' => "ไม่มีไฟล์ {$notFoundLabel} สำหรับ shipment นี้"], 404);
        }

        $content = $this->r2Service->download($storageKey);
        $extension = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION));
        $mime = self::LABEL_MIME_TYPES[strtoupper($extension)] ?? 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filenamePrefix.'.'.$extension.'"',
        ]);
    }
}
