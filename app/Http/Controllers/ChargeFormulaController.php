<?php

namespace App\Http\Controllers;

use App\Services\ChargeFormulaEvaluator;
use Illuminate\Http\Request;

class ChargeFormulaController extends Controller
{
    /**
     * Lets staff try out a MarkupRule/ChargeFixedOverride formula with made-up amounts (e.g.
     * "if Base Freight were 2,000 and Fuel Surcharge were 300, what would this formula give?")
     * before saving it for real, so a typo/wrong logic is caught immediately instead of only
     * showing up (silently, via the safe-fallback) on a real shipment quote later.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'formula' => ['required', 'string', 'max:500'],
            'values' => ['required', 'array'],
            'values.*' => ['numeric'],
        ]);

        if ($error = ChargeFormulaEvaluator::validate($data['formula'])) {
            return response()->json(['message' => $error], 422);
        }

        try {
            $result = ChargeFormulaEvaluator::evaluate($data['formula'], array_map('floatval', $data['values']));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'คำนวณไม่สำเร็จ: '.$e->getMessage()], 422);
        }

        return response()->json(['result' => round($result, 2)]);
    }
}
