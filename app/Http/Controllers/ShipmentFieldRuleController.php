<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ShipmentFieldRules;
use Illuminate\Http\Request;

class ShipmentFieldRuleController extends Controller
{
    public function __construct(private ShipmentFieldRules $fields)
    {
    }

    /** The field catalog + which fields each carrier requires (the booking form reads this too). */
    public function index()
    {
        return response()->json([
            'carriers' => $this->fields->carriers(),
            'groups' => config('shipment_fields.groups'),
            'fields' => collect($this->fields->catalog())->map(fn ($f, $key) => ['key' => $key] + $f)->values(),
            'rules' => $this->fields->rules(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $carriers = $this->fields->carriers();
        $keys = array_keys($this->fields->catalog());
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*' => ['array'],
            'rules.*.*' => ['string', 'in:'.implode(',', $keys)],
        ]);
        $unknown = array_diff(array_keys($data['rules']), $carriers);
        abort_if($unknown !== [], 422, 'Carrier ไม่ถูกต้อง: '.implode(', ', $unknown));

        $before = $this->fields->rules();
        $after = $this->fields->save($data['rules']);
        if ($before !== $after) {
            $audit->record('updated', 'shipment_field_rules', ['rules' => ['old' => $before, 'new' => $after]], 'Shipment required fields');
        }

        return $this->index();
    }
}
