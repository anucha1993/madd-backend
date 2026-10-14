<?php

namespace App\Services;

use App\Models\IntegrationSetting;

/**
 * Per-carrier "must be filled" booking fields set in /config/shipment-fields, and the label each
 * field shows on /shipment/create. The catalog of fields lives in config/shipment_fields.php.
 */
class ShipmentFieldRules
{
    public const SETTING_KEY = 'shipment.required_fields';

    public const LABELS_KEY = 'shipment.field_labels';

    /** @return array<string, array{label: string, form_label: string, group: string, step: int, requirable?: bool, always?: bool}> */
    public function catalog(): array
    {
        return config('shipment_fields.fields');
    }

    /** Fields an admin can make mandatory (label-only entries excluded). @return list<string> */
    public function requirableKeys(): array
    {
        return array_keys(array_filter($this->catalog(), fn ($f) => $f['requirable'] ?? true));
    }

    /** Staff's own form labels, by field key (only the ones changed from the default). @return array<string, string> */
    public function labels(): array
    {
        $stored = json_decode((string) IntegrationSetting::get(self::LABELS_KEY), true) ?: [];

        return array_intersect_key(array_filter($stored, fn ($v) => is_string($v) && trim($v) !== ''), $this->catalog());
    }

    /** @param array<string, ?string> $labels blank or equal to the default = back to the default */
    public function saveLabels(array $labels): array
    {
        $clean = [];
        foreach ($this->catalog() as $key => $field) {
            $label = trim((string) ($labels[$key] ?? ''));
            if ($label !== '' && $label !== $field['form_label']) {
                $clean[$key] = $label;
            }
        }
        IntegrationSetting::set(self::LABELS_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $clean;
    }

    /** @return list<string> */
    public function carriers(): array
    {
        return config('shipment_fields.carriers');
    }

    /** @return array<string, list<string>> carrier => required field keys (only known keys) */
    public function rules(): array
    {
        $stored = json_decode((string) IntegrationSetting::get(self::SETTING_KEY), true) ?: [];
        $known = $this->requirableKeys();
        $rules = [];
        foreach ($this->carriers() as $carrier) {
            $rules[$carrier] = array_values(array_intersect($known, (array) ($stored[$carrier] ?? [])));
        }

        return $rules;
    }

    /** @param array<string, list<string>> $rules */
    public function save(array $rules): array
    {
        $known = $this->requirableKeys();
        $clean = [];
        foreach ($this->carriers() as $carrier) {
            $clean[$carrier] = array_values(array_intersect($known, (array) ($rules[$carrier] ?? [])));
        }
        IntegrationSetting::set(self::SETTING_KEY, json_encode($clean));

        return $clean;
    }

    /**
     * Laravel validation rules + messages for one carrier, to run on the booking request.
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, string>}
     */
    public function validationFor(string $carrier): array
    {
        $catalog = $this->catalog();
        $rules = [];
        $messages = [];
        foreach ($this->rules()[$carrier] ?? [] as $key) {
            $rules[$key] = ['required'];
            $messages[$key.'.required'] = "{$carrier} กำหนดให้กรอก: {$catalog[$key]['label']}";
        }
        // A required per-row field also needs at least one row to check.
        foreach (['packages', 'invoice_lines'] as $list) {
            if (array_filter(array_keys($rules), fn ($k) => str_starts_with($k, $list.'.*.'))) {
                $rules[$list] = ['required', 'array', 'min:1'];
            }
        }

        return [$rules, $messages];
    }
}
