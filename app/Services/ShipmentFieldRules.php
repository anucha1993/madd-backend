<?php

namespace App\Services;

use App\Models\IntegrationSetting;

/**
 * Per-carrier "must be filled" booking fields set in /config/shipment-fields. The catalog of
 * fields that can be switched on lives in config/shipment_fields.php.
 */
class ShipmentFieldRules
{
    public const SETTING_KEY = 'shipment.required_fields';

    /** @return array<string, array{label: string, group: string, step: int}> */
    public function catalog(): array
    {
        return config('shipment_fields.fields');
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
        $known = array_keys($this->catalog());
        $rules = [];
        foreach ($this->carriers() as $carrier) {
            $rules[$carrier] = array_values(array_intersect($known, (array) ($stored[$carrier] ?? [])));
        }

        return $rules;
    }

    /** @param array<string, list<string>> $rules */
    public function save(array $rules): array
    {
        $known = array_keys($this->catalog());
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
