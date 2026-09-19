<?php

namespace App\Http\Controllers;

use App\Models\ChargeFixedOverride;
use App\Services\ChargeFormulaEvaluator;
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
            'override_type' => ['sometimes', 'in:FIXED,FORMULA'],
            'formula' => ['nullable', 'string', 'max:500'],
            'fixed_amount' => ['required_if:override_type,FIXED', 'nullable', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'in:THB,PERCENTAGE'],
            'status' => ['boolean'],
        ]);
        $data['override_type'] = $data['override_type'] ?? 'FIXED';
        $data['unit'] = $data['unit'] ?? 'THB';

        if ($data['override_type'] === 'FORMULA') {
            if ($error = ChargeFormulaEvaluator::validate($data['formula'] ?? null)) {
                return response()->json(['message' => $error], 422);
            }
        }

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
            'override_type' => ['sometimes', 'in:FIXED,FORMULA'],
            'formula' => ['nullable', 'string', 'max:500'],
            'fixed_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'in:THB,PERCENTAGE'],
            'status' => ['boolean'],
        ]);

        $overrideType = $data['override_type'] ?? $chargeFixedOverride->override_type;
        if ($overrideType === 'FORMULA') {
            if ($error = ChargeFormulaEvaluator::validate($data['formula'] ?? $chargeFixedOverride->formula)) {
                return response()->json(['message' => $error], 422);
            }
        } elseif (($data['fixed_amount'] ?? $chargeFixedOverride->fixed_amount) === null) {
            return response()->json(['message' => 'กรุณาระบุ Fixed Amount'], 422);
        }

        $chargeFixedOverride->update($data);

        return $chargeFixedOverride->load(['agentAccount.agent', 'chargeCode']);
    }

    public function destroy(ChargeFixedOverride $chargeFixedOverride)
    {
        $chargeFixedOverride->delete();

        return response()->json(['message' => 'ลบ Fixed Amount เรียบร้อย']);
    }
}
