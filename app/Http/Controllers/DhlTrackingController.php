<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Services\DhlTrackingService;
use Illuminate\Http\Request;

class DhlTrackingController extends Controller
{
    public function __construct(private DhlTrackingService $dhlTrackingService)
    {
    }

    public function track(Request $request)
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:50'],
            'agent_account_id' => ['nullable', 'integer'],
        ]);

        $accountsQuery = AgentAccount::with('agent')->where('status', true);
        if (! empty($data['agent_account_id'])) {
            $accountsQuery->where('id', $data['agent_account_id']);
        }
        $account = $accountsQuery->get()->first(fn ($a) => $a->agent?->agent_code === 'DHL' && $a->basic_auth_username && $a->basic_auth_password);

        if (! $account) {
            return response()->json(['message' => 'ไม่พบบัญชี DHL ที่เปิดใช้งานอยู่'], 400);
        }

        try {
            $result = $this->dhlTrackingService->trackByNumber($account->basic_auth_username, $account->basic_auth_password, $data['tracking_number'], $account->mode);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result + [
            'account' => ['id' => $account->id, 'username_acc' => $account->username_acc, 'agent_code' => 'DHL'],
        ]);
    }
}
