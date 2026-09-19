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
            $query->where('tracking_number', 'like', "%{$search}%");
        }
        if ($carrier = $request->query('carrier')) {
            $query->where('carrier', $carrier);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
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
        ];

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
            'commercial_invoice_storage_key' => $this->uploadShipmentDocument('invoice', $result['trackingNumber'], $result['commercialInvoiceBase64'] ?? null, $result['commercialInvoiceFormat'] ?? null),
            'raw_response' => $result['raw'],
        ]);

        return response()->json($record);
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
                    $mpdf->AddPage();
                    $mpdf->Image($path, 10, 10, 190, 0, '', '', false, false);
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
     * booked, kept separate from the label(s) actually stuck on the boxes. Never present for
     * DHL (see DhlShipmentService::createShipment).
     */
    public function waybill(Shipment $shipment)
    {
        $storageKey = $shipment->waybill_storage_key;
        if (! $storageKey) {
            $recoveredKey = $this->recoverUpsDocument($shipment, $shipment->tracking_number, 'waybill');
            if ($recoveredKey) {
                $storageKey = $recoveredKey;
                $shipment->update(['waybill_storage_key' => $recoveredKey]);
            }
        }

        return $this->streamDocument($storageKey, 'waybill-'.$shipment->tracking_number, 'waybill');
    }

    /**
     * Commercial Invoice for customs — only present when the carrier actually returned one
     * (DHL auto-generates it for customs-declarable shipments; UPS only if InternationalForms
     * was requested, which we don't do yet).
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
