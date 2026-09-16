<?php

namespace App\Http\Controllers;

use App\Models\AddonItem;
use App\Models\AgentAccount;
use App\Models\Shipment;
use App\Services\DhlShipmentService;
use App\Services\R2Service;
use App\Services\UpsShipmentService;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function __construct(
        private UpsShipmentService $upsShipmentService,
        private DhlShipmentService $dhlShipmentService,
        private R2Service $r2Service,
    ) {
    }

    private const LABEL_MIME_TYPES = [
        'PDF' => 'application/pdf',
        'GIF' => 'image/gif',
        'ZPL' => 'application/octet-stream',
    ];

    /**
     * Lists booked/failed shipments for the "My Shipments" page — newest first, with optional
     * search (tracking number) / carrier / status filters.
     */
    public function index(Request $request)
    {
        $query = Shipment::with('agentAccount.agent')->latest();

        if ($search = $request->query('search')) {
            $query->where('tracking_number', 'like', "%{$search}%");
        }
        if ($carrier = $request->query('carrier')) {
            $query->where('carrier', $carrier);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(20));
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
            'description' => $data['packages'][0]['description'] ?? null,
        ];

        $recordAttributes = [
            'agent_account_id' => $account->id,
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
                $token = $this->upsShipmentService->getAccessToken($account->client_id, $account->client_secret, $account->mode);
                $result = $this->upsShipmentService->createShipment($token, [
                    'id' => $account->id,
                    'username_acc' => $account->username_acc,
                ], $shipment, $account->mode);
            }
        } catch (\Throwable $e) {
            $record = Shipment::create($recordAttributes + [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage(), 'shipment_id' => $record->id], 422);
        }

        $labelStorageKey = null;
        if ($result['labelBase64']) {
            try {
                $mime = self::LABEL_MIME_TYPES[$result['labelFormat']] ?? 'application/octet-stream';
                $extension = strtolower($result['labelFormat'] ?? 'bin');
                $upload = $this->r2Service->upload(
                    "shipment-{$result['trackingNumber']}.{$extension}",
                    base64_decode($result['labelBase64']),
                    $mime,
                );
                $labelStorageKey = $upload['key'];
            } catch (\Throwable $e) {
                // Label upload failing must not lose an already-booked real shipment — the
                // tracking number/raw response are still saved, label can be re-fetched/re-uploaded later.
                report($e);
            }
        }

        $record = Shipment::create($recordAttributes + [
            'status' => 'booked',
            'tracking_number' => $result['trackingNumber'],
            'label_storage_key' => $labelStorageKey,
            'raw_response' => $result['raw'],
        ]);

        return response()->json($record);
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
     * A booked Shipment is otherwise fully immutable (it's a real carrier air waybill already) —
     * only these 3 reference numbers are still editable after the fact, since they're just
     * internal bookkeeping fields, never sent to the carrier or affecting the actual shipment.
     */
    public function updateRefs(Request $request, Shipment $shipment)
    {
        $data = $request->validate([
            'ref_invoice_no' => ['nullable', 'string', 'max:255'],
            'ref_insurance_no' => ['nullable', 'string', 'max:255'],
            'ref_purchase_no' => ['nullable', 'string', 'max:255'],
        ]);

        $shipment->update($data);

        return $shipment->load('agentAccount.agent', 'createdBy');
    }

    /**
     * Streams the label file back through OUR OWN auth — the R2 bucket is never made public
     * (see R2Service::download). Served `inline` (not `attachment`) with its real content type
     * so the frontend can open it directly in a new tab, ready to print, instead of forcing a save-to-disk dialog.
     */
    public function label(Shipment $shipment)
    {
        if (! $shipment->label_storage_key) {
            return response()->json(['error' => 'ไม่มีไฟล์ label สำหรับ shipment นี้'], 404);
        }

        $content = $this->r2Service->download($shipment->label_storage_key);
        $extension = strtolower(pathinfo($shipment->label_storage_key, PATHINFO_EXTENSION));
        $mime = self::LABEL_MIME_TYPES[strtoupper($extension)] ?? 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="shipment-'.$shipment->tracking_number.'.'.$extension.'"',
        ]);
    }
}
