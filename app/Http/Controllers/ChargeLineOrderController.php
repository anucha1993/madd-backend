<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSetting;
use Illuminate\Http\Request;

/**
 * The order charge lines are listed in on a quote (/config/charge-names → "ลำดับการแสดง"), per
 * carrier: an ordered list of codes, each optionally pinned to the top or the bottom (e.g. "Total"
 * always last). Codes not in the list keep the carrier's order, after the listed ones. Display only —
 * amounts and totals are untouched.
 */
class ChargeLineOrderController extends Controller
{
    public const KEY = 'rate.charge_line_order';

    /** Open to every signed-in user — every screen that lists charge lines needs it (no prices in here). */
    public function index()
    {
        return response()->json(self::stored());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'carrier' => ['required', 'in:UPS,DHL'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.code' => ['required', 'string', 'max:50', 'distinct'],
            'lines.*.pin' => ['nullable', 'in:top,bottom'],
        ]);
        $all = IntegrationSetting::mutateJson(self::KEY, function (array $all) use ($data) {
            $all[$data['carrier']] = array_map(fn ($l) => ['code' => (string) $l['code'], 'pin' => $l['pin'] ?? null], $data['lines']);

            return $all;
        }) + ['UPS' => [], 'DHL' => []];

        return response()->json($all);
    }

    /** @return array<string, list<array{code: string, pin: ?string}>> */
    private static function stored(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        return (is_array($saved) ? $saved : []) + ['UPS' => [], 'DHL' => []];
    }
}
