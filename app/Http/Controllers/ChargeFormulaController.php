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
            // Per-box weights for BOX_OVER(kg); without them, {BOX} boxes of {W}/{BOX} kg each.
            'package_weights' => ['nullable', 'array', 'max:500'],
            'package_weights.*' => ['numeric', 'min:0'],
        ]);

        $values = array_map('floatval', $data['values']);
        $weights = array_map('floatval', $data['package_weights'] ?? []);
        if (! $weights && ($boxes = (int) ($values['BOX'] ?? 0)) > 0) {
            $weights = array_fill(0, min($boxes, 500), ($values['W'] ?? $values['BILLED_WEIGHT'] ?? 0) / $boxes);
        }

        if ($error = ChargeFormulaEvaluator::validate($data['formula'])) {
            return response()->json(['message' => $error], 422);
        }

        try {
            $result = ChargeFormulaEvaluator::evaluate($data['formula'], $values, ['package_weights' => $weights]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'คำนวณไม่สำเร็จ: '.$e->getMessage()], 422);
        }

        return response()->json(['result' => round($result, 2)]);
    }
}
