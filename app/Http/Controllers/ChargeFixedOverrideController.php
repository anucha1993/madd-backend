<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Models\ChargeFixedOverride;
use App\Services\ChargeFormulaEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Copy a source account's Fixed Charges / Formulas onto other accounts of the SAME carrier
     * (charge codes are per provider, so a UPS code means nothing on a DHL account). A code the
     * target already has is skipped, or replaced when `overwrite` is true.
     */
    public function clone(Request $request)
    {
        $data = $request->validate([
            'source_agent_account_id' => ['required', 'integer', 'exists:agent_accounts,id'],
            'target_agent_account_ids' => ['required', 'array', 'min:1'],
            'target_agent_account_ids.*' => ['integer', 'distinct', 'exists:agent_accounts,id'],
            // Optional subset of the source's rows — omitted means all of them.
            'override_ids' => ['nullable', 'array'],
            'override_ids.*' => ['integer'],
            'overwrite' => ['boolean'],
        ]);

        $source = AgentAccount::findOrFail($data['source_agent_account_id']);
        $targets = AgentAccount::whereIn('id', $data['target_agent_account_ids'])
            ->where('id', '!=', $source->id)
            ->get();

        if ($targets->contains(fn ($t) => $t->agent_id !== $source->agent_id)) {
            return response()->json(['message' => 'Clone ได้เฉพาะบัญชีของ Agent เดียวกันเท่านั้น'], 422);
        }

        $sourceOverrides = ChargeFixedOverride::where('agent_account_id', $source->id)
            ->when(! empty($data['override_ids']), fn ($q) => $q->whereIn('id', $data['override_ids']))
            ->get();

        $overwrite = $data['overwrite'] ?? false;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($targets, $sourceOverrides, $overwrite, &$created, &$updated, &$skipped) {
            foreach ($targets as $target) {
                foreach ($sourceOverrides as $override) {
                    $values = $override->only(['override_type', 'formula', 'fixed_amount', 'unit', 'status']);
                    $existing = ChargeFixedOverride::where('agent_account_id', $target->id)
                        ->where('charge_code_id', $override->charge_code_id)
                        ->first();

                    if (! $existing) {
                        ChargeFixedOverride::create([
                            'agent_account_id' => $target->id,
                            'charge_code_id' => $override->charge_code_id,
                            ...$values,
                        ]);
                        $created++;
                    } elseif ($overwrite) {
                        $existing->update($values);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            }
        });

        return response()->json([
            'message' => "Clone เรียบร้อย — เพิ่ม {$created}, เขียนทับ {$updated}, ข้าม {$skipped}",
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    public function destroy(ChargeFixedOverride $chargeFixedOverride)
    {
        $chargeFixedOverride->delete();

        return response()->json(['message' => 'ลบ Fixed Amount เรียบร้อย']);
    }
}
