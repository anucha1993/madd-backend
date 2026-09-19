<?php

namespace App\Http\Controllers;

use App\Models\MarkupRule;
use App\Services\ChargeFormulaEvaluator;
use Illuminate\Http\Request;

class MarkupRuleController extends Controller
{
    public function index(Request $request)
    {
        $query = MarkupRule::with(['agent', 'agentAccount', 'chargeCode']);

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->integer('agent_id'));
        }

        if ($request->filled('agent_account_id')) {
            $query->where('agent_account_id', $request->integer('agent_account_id'));
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:agents,id'],
            'agent_account_id' => ['required', 'integer', 'exists:agent_accounts,id'],
            'charge_code_id' => ['required', 'integer', 'exists:charge_codes,id'],
            'rule_type' => ['sometimes', 'in:SIMPLE,FORMULA'],
            'formula' => ['nullable', 'string', 'max:500'],
            'value' => ['required_if:rule_type,SIMPLE', 'nullable', 'numeric', 'min:0'],
            'unit' => ['required_if:rule_type,SIMPLE', 'nullable', 'in:PERCENTAGE,BAHT'],
            'status' => ['boolean'],
        ]);
        $data['rule_type'] = $data['rule_type'] ?? 'SIMPLE';

        if ($data['rule_type'] === 'FORMULA') {
            if ($error = ChargeFormulaEvaluator::validate($data['formula'] ?? null)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $exists = MarkupRule::where('agent_account_id', $data['agent_account_id'])
            ->where('charge_code_id', $data['charge_code_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'มีกฎ Markup สำหรับบัญชีและรายการนี้อยู่แล้ว'], 422);
        }

        $rule = MarkupRule::create($data);

        return response()->json($rule->load(['agent', 'agentAccount', 'chargeCode']), 201);
    }

    public function update(Request $request, MarkupRule $markupRule)
    {
        $data = $request->validate([
            'rule_type' => ['sometimes', 'in:SIMPLE,FORMULA'],
            'formula' => ['nullable', 'string', 'max:500'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'nullable', 'in:PERCENTAGE,BAHT'],
            'status' => ['boolean'],
        ]);

        $ruleType = $data['rule_type'] ?? $markupRule->rule_type;
        if ($ruleType === 'FORMULA') {
            if ($error = ChargeFormulaEvaluator::validate($data['formula'] ?? $markupRule->formula)) {
                return response()->json(['message' => $error], 422);
            }
        } elseif (($data['value'] ?? $markupRule->value) === null || ($data['unit'] ?? $markupRule->unit) === null) {
            return response()->json(['message' => 'กรุณาระบุ Value และ Unit'], 422);
        }

        $markupRule->update($data);

        return $markupRule->load(['agent', 'agentAccount', 'chargeCode']);
    }

    public function destroy(MarkupRule $markupRule)
    {
        $markupRule->delete();

        return response()->json(['message' => 'ลบกฎ Markup เรียบร้อย']);
    }
}
