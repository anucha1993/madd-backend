<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Services\UpsAddressValidationService;
use Illuminate\Http\Request;

class AddressValidationController extends Controller
{
    public function __construct(private UpsAddressValidationService $upsAddressValidationService)
    {
    }

    /**
     * Validates an international Ship To address via UPS Street Level Address Validation.
     * Uses any active, API-enabled UPS account (validation doesn't book anything, so it
     * doesn't matter which UPS sub-account is used) — pass agent_account_id to pin one.
     */
    public function validate(Request $request)
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:255'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'address3' => ['nullable', 'string', 'max:255'],
            'agent_account_id' => ['nullable', 'integer'],
        ]);

        $accountsQuery = AgentAccount::with('agent')->where('status', true)->where('is_api_enabled', true);
        if (! empty($data['agent_account_id'])) {
            $accountsQuery->where('id', $data['agent_account_id']);
        }
        $account = $accountsQuery->get()->first(fn ($a) => $a->agent?->agent_code === 'UPS' && $a->client_id && $a->client_secret);

        if (! $account) {
            return response()->json(['message' => 'ไม่พบบัญชี UPS ที่เปิดใช้งานอยู่สำหรับตรวจสอบที่อยู่'], 400);
        }

        try {
            $result = $this->upsAddressValidationService->validate($account->client_id, $account->client_secret, [
                'country' => strtoupper($data['country']),
                'city' => $data['city'],
                'stateCode' => $data['state_code'] ?? null,
                'postcode' => $data['postcode'] ?? null,
                'address' => $data['address'] ?? null,
                'address2' => $data['address2'] ?? null,
                'address3' => $data['address3'] ?? null,
            ], $account->mode);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
