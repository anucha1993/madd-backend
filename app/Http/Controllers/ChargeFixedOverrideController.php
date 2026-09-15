<?php

namespace App\Http\Controllers;

use App\Models\ChargeFixedOverride;
use Illuminate\Http\Request;

class ChargeFixedOverrideController extends Controller
{
    public function index(Request $request)
    {
        $query = ChargeFixedOverride::with(['agentAccount.agent', 'chargeCode']);

        if ($request->filled('agent_account_id')) {
            $query->where('agent_account_id', $request->integer('agent_account_id'));
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_account_id' => ['required', 'integer', 'exists:agent_accounts,id'],
            'charge_code_id' => ['required', 'integer', 'exists:charge_codes,id'],
            'fixed_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['boolean'],
        ]);

        $exists = ChargeFixedOverride::where('agent_account_id', $data['agent_account_id'])
            ->where('charge_code_id', $data['charge_code_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'มีค่า Fixed Amount สำหรับบัญชีและรายการนี้อยู่แล้ว'], 422);
        }

        $override = ChargeFixedOverride::create($data);

        return response()->json($override->load(['agentAccount.agent', 'chargeCode']), 201);
    }

    public function update(Request $request, ChargeFixedOverride $chargeFixedOverride)
    {
        $data = $request->validate([
            'fixed_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'status' => ['boolean'],
        ]);

        $chargeFixedOverride->update($data);

        return $chargeFixedOverride->load(['agentAccount.agent', 'chargeCode']);
    }

    public function destroy(ChargeFixedOverride $chargeFixedOverride)
    {
        $chargeFixedOverride->delete();

        return response()->json(['message' => 'ลบ Fixed Amount เรียบร้อย']);
    }
}
