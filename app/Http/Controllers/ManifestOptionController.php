<?php

namespace App\Http\Controllers;

use App\Models\ManifestOption;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManifestOptionController extends Controller
{
    /** Fixed set of dropdown groups used on the Manifest form (per "คำที่ใช้ในฟอร์ม manifest" reference sheet). */
    public const GROUPS = [
        'customer_type', 'payment_option', 'zone', 'destination', 'charge_code', 'insurance_code', 'form_charge',
        'bill_transportation_to', 'bill_duty_tax_to',
    ];

    public function index(Request $request)
    {
        $query = ManifestOption::query();

        if ($request->filled('group')) {
            $query->where('group', $request->string('group'));
        }

        return $query->orderBy('group')->orderBy('provider')->orderBy('sort_order')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'group' => ['required', 'string', Rule::in(self::GROUPS)],
            'provider' => ['nullable', 'string', Rule::in(['UPS', 'DHL'])],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['boolean'],
        ]);

        $exists = ManifestOption::where('group', $data['group'])
            ->where('provider', $data['provider'] ?? null)
            ->where('code', $data['code'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'มี Code นี้อยู่แล้วในกลุ่มนี้'], 422);
        }

        $data['sort_order'] = (int) ManifestOption::where('group', $data['group'])->max('sort_order') + 1;

        return response()->json(ManifestOption::create($data), 201);
    }

    public function update(Request $request, ManifestOption $manifestOption)
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(['UPS', 'DHL'])],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('manifest_options', 'code')
                    ->where(fn ($q) => $q->where('group', $manifestOption->group)->where('provider', $request->input('provider', $manifestOption->provider)))
                    ->ignore($manifestOption->id),
            ],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['boolean'],
        ]);

        $manifestOption->update($data);

        return $manifestOption;
    }

    public function destroy(ManifestOption $manifestOption)
    {
        $manifestOption->delete();

        return response()->json(['message' => 'ลบตัวเลือกเรียบร้อย']);
    }
}
