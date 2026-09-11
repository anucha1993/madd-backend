<?php

namespace App\Http\Controllers;

use App\Models\MarkupRule;
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
            'value' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'in:PERCENTAGE,BAHT'],
            'status' => ['boolean'],
        ]);

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
            'value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'required', 'in:PERCENTAGE,BAHT'],
            'status' => ['boolean'],
        ]);

        $markupRule->update($data);

        return $markupRule->load(['agent', 'agentAccount', 'chargeCode']);
    }

    public function destroy(MarkupRule $markupRule)
    {
        $markupRule->delete();

        return response()->json(['message' => 'ลบกฎ Markup เรียบร้อย']);
    }
}
