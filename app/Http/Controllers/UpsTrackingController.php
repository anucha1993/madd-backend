<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Services\UpsTrackingService;
use Illuminate\Http\Request;

class UpsTrackingController extends Controller
{
    public function __construct(private UpsTrackingService $upsTrackingService)
    {
    }

    public function track(Request $request)
    {
        $data = $request->validate([
            'inquiry_number' => ['required_without:reference_number', 'nullable', 'string', 'min:7', 'max:34'],
            'reference_number' => ['required_without:inquiry_number', 'nullable', 'string', 'max:50'],
            'agent_account_id' => ['nullable', 'integer'],
            'return_pod' => ['boolean'],
            'return_signature' => ['boolean'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'from_pickup_date' => ['nullable', 'date_format:Y-m-d'],
            'to_pickup_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $accountsQuery = AgentAccount::with('agent')->where('status', true);
        if (! empty($data['agent_account_id'])) {
            $accountsQuery->where('id', $data['agent_account_id']);
        }
        $account = $accountsQuery->get()->first(fn ($a) => $a->agent?->agent_code === 'UPS' && $a->client_id && $a->client_secret);

        if (! $account) {
            return response()->json(['message' => 'ไม่พบบัญชี UPS ที่เปิดใช้งานอยู่'], 400);
        }

        $options = [
            'returnPOD' => (bool) ($data['return_pod'] ?? false),
            'returnSignature' => (bool) ($data['return_signature'] ?? false),
            'offset' => $data['offset'] ?? null,
            'count' => $data['count'] ?? null,
            'fromPickUpDate' => $data['from_pickup_date'] ?? null,
            'toPickUpDate' => $data['to_pickup_date'] ?? null,
        ];

        try {
            $result = ! empty($data['reference_number'])
                ? $this->upsTrackingService->trackByReference($account->client_id, $account->client_secret, $data['reference_number'], $options, $account->mode)
                : $this->upsTrackingService->trackByInquiry($account->client_id, $account->client_secret, $data['inquiry_number'], $options, $account->mode);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result + [
            'account' => ['id' => $account->id, 'username_acc' => $account->username_acc, 'agent_code' => 'UPS'],
        ]);
    }
}
