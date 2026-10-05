<?php

namespace App\Http\Controllers;

use App\Models\ChargeCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff's own display names for carrier charge codes (/config/charge-names) — e.g. BASE →
 * "ค่าขนส่ง". Applied wherever a charge line is shown and on suggested Receipt / Tax Invoice
 * lines; the carrier's own description stored in rate_quote is never touched.
 */
class ChargeDisplayNameController extends Controller
{
    /**
     * Only provider/code/name/rule of codes that HAVE a display name or rule — needed by every screen that
     * renders charge lines, so it's open to any signed-in user (no markup values in here).
     */
    public function index()
    {
        return ChargeCode::query()
            ->withDisplayName()
            ->orderBy('provider')
            ->orderBy('code')
            ->get(['provider', 'code', 'display_name', 'display_rule']);
    }

    public function update(Request $request, ChargeCode $chargeCode)
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:150'],
            ...$this->ruleValidation(),
        ]);

        $changes = ['display_name' => $this->normalize($data['display_name'] ?? null)];
        if ($request->exists('display_rule')) {
            $changes['display_rule'] = $this->normalizeRule($data['display_rule'] ?? null);
        }
        $chargeCode->update($changes);

        return $chargeCode;
    }

    /**
     * For a carrier code the seeded list doesn't have yet (e.g. a new DHL service code seen on a
     * quote) — adds it as a real carrier code (not is_custom, which means "never from the API").
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:UPS,DHL'],
            'code' => ['required', 'string', 'max:50'],
            'label' => ['nullable', 'string', 'max:100'],
            'display_name' => ['nullable', 'required_without:display_rule', 'string', 'max:150'],
            ...$this->ruleValidation(),
        ]);

        if (ChargeCode::where('provider', $data['provider'])->where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'มี Charge Code นี้อยู่แล้ว — แก้ชื่อที่แถวของ Code นั้นได้เลย'], 422);
        }

        $chargeCode = ChargeCode::create([
            'provider' => $data['provider'],
            'code' => $data['code'],
            'label' => $this->normalize($data['label'] ?? null) ?? $data['code'],
            'display_name' => $this->normalize($data['display_name'] ?? null),
            'display_rule' => $this->normalizeRule($data['display_rule'] ?? null),
            'is_custom' => false,
        ]);

        return response()->json($chargeCode, 201);
    }

    /** IF {code} op value → name (e.g. {190} > 0 → "BBBBB"); otherwise display_name applies. */
    private function ruleValidation(): array
    {
        return [
            'display_rule' => ['nullable', 'array'],
            'display_rule.code' => ['required_with:display_rule', 'string', 'max:50'],
            'display_rule.op' => ['nullable', Rule::in(ChargeCode::DISPLAY_RULE_OPERATORS)],
            'display_rule.value' => ['nullable', 'numeric'],
            'display_rule.name' => ['required_with:display_rule', 'string', 'max:150'],
        ];
    }

    private function normalizeRule(?array $rule): ?array
    {
        $code = trim((string) ($rule['code'] ?? ''));
        $name = $this->normalize($rule['name'] ?? null);
        if ($code === '' || $name === null) {
            return null;
        }

        return ['code' => $code, 'op' => $rule['op'] ?? '>', 'value' => (float) ($rule['value'] ?? 0), 'name' => $name];
    }

    private function normalize(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }
}
