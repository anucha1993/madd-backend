<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSetting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Layout of the Ship From / Ship To cards on /shipment/create — field order and width (full / half
 * row), one layout for every account, arranged by an admin right on the form ("จัดตำแหน่ง").
 * Field keys are the shipment_fields.php keys; unknown or missing ones fall back to the default
 * layout in the browser, so a new field never disappears.
 */
class ShipmentFormLayoutController extends Controller
{
    public const KEY = 'shipment.form_layout';

    public const SECTIONS = ['origin', 'destination'];

    public function show()
    {
        return response()->json(self::stored());
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'section' => ['required', 'in:'.implode(',', self::SECTIONS)],
            'fields' => ['present', 'array', 'max:50'],
            'fields.*.key' => ['required', 'string', 'max:60', 'distinct'],
            'fields.*.width' => ['required', 'in:full,half'],
        ]);
        $all = self::stored();
        $before = $all[$data['section']];
        $all[$data['section']] = array_map(fn ($f) => ['key' => $f['key'], 'width' => $f['width']], $data['fields']);
        IntegrationSetting::set(self::KEY, json_encode($all));
        if ($before !== $all[$data['section']]) {
            $audit->record('updated', 'shipment_form_layout', [$data['section'] => ['old' => $before, 'new' => $all[$data['section']]]], 'Create Shipment form layout');
        }

        return response()->json($all);
    }

    /** @return array<string, list<array{key: string, width: string}>> empty list = the default layout */
    private static function stored(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        return (is_array($saved) ? $saved : []) + array_fill_keys(self::SECTIONS, []);
    }
}
