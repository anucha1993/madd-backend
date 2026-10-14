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

    /** The field catalog + which fields each carrier requires + form labels (the booking form reads this too). */
    public function index()
    {
        $labels = $this->fields->labels();

        return response()->json([
            'carriers' => $this->fields->carriers(),
            'groups' => config('shipment_fields.groups'),
            'fields' => collect($this->fields->catalog())->map(fn ($f, $key) => [
                'key' => $key,
                'label' => $f['label'],
                'group' => $f['group'],
                'step' => $f['step'],
                'requirable' => $f['requirable'] ?? true,
                'always' => $f['always'] ?? false,
                'form_label' => $labels[$key] ?? $f['form_label'],
                'default_form_label' => $f['form_label'],
            ])->values(),
            'rules' => $this->fields->rules(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $carriers = $this->fields->carriers();
        $keys = $this->fields->requirableKeys();
        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*' => ['array'],
            'rules.*.*' => ['string', 'in:'.implode(',', $keys)],
            // Form labels on /shipment/create — blank = back to the default.
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['nullable', 'string', 'max:100'],
        ]);
        $unknown = array_diff(array_keys($data['rules']), $carriers);
        abort_if($unknown !== [], 422, 'Carrier ไม่ถูกต้อง: '.implode(', ', $unknown));

        $before = $this->fields->rules();
        $after = $this->fields->save($data['rules']);
        if ($before !== $after) {
            $audit->record('updated', 'shipment_field_rules', ['rules' => ['old' => $before, 'new' => $after]], 'Shipment required fields');
        }
        if (array_key_exists('labels', $data)) {
            $labelsBefore = $this->fields->labels();
            $labelsAfter = $this->fields->saveLabels($data['labels']);
            if ($labelsBefore !== $labelsAfter) {
                $audit->record('updated', 'shipment_field_rules', ['labels' => ['old' => $labelsBefore, 'new' => $labelsAfter]], 'Shipment form labels');
            }
        }

        return $this->index();
    }
}
