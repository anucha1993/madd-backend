<?php

namespace App\Http\Controllers;

use App\Exceptions\UpsTokenExpiredException;
use App\Models\AgentAccount;
use App\Models\Pickup;
use App\Models\Shipment;
use App\Services\DhlPickupService;
use App\Services\UpsPickupService;
use App\Services\UpsShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Schedules/cancels courier pickups — a SEPARATE concept from booking a Shipment (the Ship API
 * only creates the label/waybill; UPS/DHL require a distinct Pickup request for the courier to
 * actually come collect the boxes). Live-verified against both carriers: a pickup is NOT tied to
 * specific tracking numbers — only total piece count/weight + address + time window — so ONE
 * pickup can (and normally does) cover MULTIPLE shipments booked for the same day/address.
 */
class PickupController extends Controller
{
    public function __construct(
        private UpsPickupService $upsPickupService,
        private DhlPickupService $dhlPickupService,
        private UpsShipmentService $upsShipmentService,
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

    public function index(Request $request)
    {
        $query = Pickup::with('agentAccount.agent', 'shipments')->latest();

        if ($carrier = $request->query('carrier')) {
            $query->where('carrier', $carrier);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_account_id' => ['required', 'integer'],
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'pickup_date' => ['required', 'date'],
            'ready_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i', 'after:ready_time'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:20'],
            'reference_number' => ['nullable', 'string', 'max:255'],
        ]);

        $account = AgentAccount::with('agent')->where('status', true)->find($data['agent_account_id']);
        if (! $account) {
            return response()->json(['error' => 'ไม่พบบัญชี Carrier ที่เลือกไว้ หรือบัญชีถูกปิดใช้งานแล้ว'], 400);
        }

        $shipments = Shipment::where('agent_account_id', $account->id)
            ->where('status', 'booked')
            ->whereIn('id', $data['shipment_ids'])
            ->with(['pickups' => fn ($q) => $q->where('status', 'requested')])
            ->get();
        if ($shipments->isEmpty()) {
            return response()->json(['error' => 'ไม่พบ Shipment ที่เลือกไว้ (ต้องเป็น status booked และอยู่ในบัญชีเดียวกัน)'], 422);
        }

        // A Shipment already sitting in an active (requested) Pickup must not be added to ANOTHER
        // one — the courier would otherwise be asked to collect the same boxes twice. Staff must
        // cancel/reschedule the existing Pickup first (see PickupController::cancel()).
        $alreadyScheduled = $shipments->filter(fn (Shipment $s) => $s->pickups->isNotEmpty());
        if ($alreadyScheduled->isNotEmpty()) {
            return response()->json([
                'error' => 'Shipment ต่อไปนี้มี Pickup ที่นัดหมายไว้แล้ว กรุณายกเลิก Pickup เดิมก่อนถ้าต้องการนัดใหม่: '
                    .$alreadyScheduled->pluck('tracking_number')->filter()->implode(', '),
            ], 422);
        }

        $totalPieces = $shipments->sum(fn (Shipment $s) => collect($s->packages ?? [])->sum(fn ($p) => (int) ($p['quantity'] ?? 1)));
        $totalWeight = $shipments->sum(fn (Shipment $s) => collect($s->packages ?? [])->sum(fn ($p) => (float) ($p['weight'] ?? 0) * (int) ($p['quantity'] ?? 1)));

        $addressSnapshot = [
            'contact_name' => $data['contact_name'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'],
            'city' => $data['city'],
            'postcode' => $data['postcode'],
        ];

        $pickup = Pickup::create([
            'agent_account_id' => $account->id,
            'created_by' => $request->user()?->id,
            'carrier' => $account->agent->agent_code,
            'status' => 'requested',
            'pickup_date' => $data['pickup_date'],
            'ready_time' => $data['ready_time'],
            'close_time' => $data['close_time'],
            'address' => $addressSnapshot,
            'total_weight' => $totalWeight,
            'total_pieces' => $totalPieces,
        ]);
        $pickup->shipments()->attach($shipments->pluck('id'));

        try {
            if ($account->agent->agent_code === 'UPS') {
                // Group pieces per Service Code — UPS's PickupPiece is an array of
                // {ServiceCode, Quantity, DestinationCountryCode}, one entry per service used.
                $pickupPieceGroup = $shipments->groupBy('service_code')->first();

                $result = $this->withUpsToken($account, fn ($upsToken) => $this->upsPickupService->createPickup($upsToken, [
                    'accountNumber' => $account->username_acc,
                    'contactName' => $data['contact_name'] ?? $account->agent->name ?? 'Shipper',
                    'companyName' => $data['company_name'] ?? null,
                    'phone' => $data['phone'] ?? '0000000000',
                    'address' => $data['address'],
                    'city' => $data['city'],
                    'postcode' => $data['postcode'],
                    'countryCode' => 'TH',
                    'pickupDate' => Carbon::parse($data['pickup_date'])->format('Ymd'),
                    'readyTime' => str_replace(':', '', $data['ready_time']),
                    'closeTime' => str_replace(':', '', $data['close_time']),
                    'totalWeightKg' => $totalWeight ?: 1,
                    'totalPieces' => $totalPieces,
                    // UPS Pickup API's ServiceCode is the same enum as the Shipping API's
                    // Service.Code, but zero-padded to 3 digits (e.g. "65" -> "065").
                    'serviceCode' => str_pad($pickupPieceGroup->first()->service_code, 3, '0', STR_PAD_LEFT),
                    'destinationCountryCode' => $pickupPieceGroup->first()->destination['country'] ?? 'US',
                    'referenceNumber' => $data['reference_number'] ?? "MADD Pickup #{$pickup->id}",
                ], $account->mode));

                $pickup->update([
                    'carrier_reference' => $result['prn'],
                    'raw_response' => $result['raw'],
                ]);
            } else {
                $result = $this->dhlPickupService->createPickup([
                    'basic_auth_username' => $account->basic_auth_username,
                    'basic_auth_password' => $account->basic_auth_password,
                    'mode' => $account->mode,
                ], [
                    'accountNumber' => $account->username_acc,
                    'contactName' => $data['contact_name'] ?? $account->agent->name ?? 'Shipper',
                    'companyName' => $data['company_name'] ?? null,
                    'phone' => $data['phone'] ?? '0000000000',
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'],
                    'city' => $data['city'],
                    'postcode' => $data['postcode'],
                    'countryCode' => 'TH',
                    'plannedPickupDateAndTime' => Carbon::parse($data['pickup_date'].' '.$data['ready_time'])->format('Y-m-d\TH:i:s \G\M\TP'),
                    'closeTime' => $data['close_time'],
                    'totalWeightKg' => $totalWeight ?: 1,
                    'totalPieces' => $totalPieces,
                    'productCode' => $shipments->first()->service_code,
                ]);

                $pickup->update([
                    'carrier_reference' => $result['dispatchConfirmationNumber'],
                    'raw_response' => $result['raw'],
                ]);
            }
        } catch (\Throwable $e) {
            $pickup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage(), 'pickup_id' => $pickup->id], 422);
        }

        return response()->json($pickup->load('agentAccount.agent', 'shipments'));
    }

    public function cancel(Request $request, Pickup $pickup)
    {
        if ($pickup->status !== 'requested') {
            return response()->json(['error' => 'Pickup นี้ไม่ได้อยู่ในสถานะที่ยกเลิกได้'], 422);
        }
        if (! $pickup->carrier_reference) {
            return response()->json(['error' => 'ไม่มีเลขอ้างอิงจาก Carrier สำหรับ Pickup นี้ ยกเลิกไม่ได้'], 422);
        }

        $data = $request->validate([
            'requestor_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $pickup->loadMissing('agentAccount.agent');
        $account = $pickup->agentAccount;

        try {
            if ($pickup->carrier === 'UPS') {
                $this->withUpsToken($account, fn ($token) => $this->upsPickupService->cancelPickup($token, $pickup->carrier_reference, $account->mode));
            } else {
                $this->dhlPickupService->cancelPickup([
                    'basic_auth_username' => $account->basic_auth_username,
                    'basic_auth_password' => $account->basic_auth_password,
                    'mode' => $account->mode,
                    'username_acc' => $account->username_acc,
                ], $pickup->carrier_reference, $data['requestor_name'] ?? 'MADD Staff', $data['reason'] ?? 'Cancelled by staff');
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $pickup->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $pickup->load('agentAccount.agent', 'shipments');
    }
}
