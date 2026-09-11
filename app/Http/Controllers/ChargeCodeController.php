<?php

namespace App\Http\Controllers;

use App\Models\ChargeCode;
use Illuminate\Http\Request;

class ChargeCodeController extends Controller
{
    public function index(Request $request)
    {
        $query = ChargeCode::query();

        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider'));
        }

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($sub) use ($search) {
                $sub->where('code', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('provider')->orderBy('category')->orderBy('label')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:10'],
            'code' => ['required', 'string', 'max:50'],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
        ]);

        $exists = ChargeCode::where('provider', $data['provider'])->where('code', $data['code'])->exists();
        if ($exists) {
            return response()->json(['message' => 'มี Charge Code นี้สำหรับผู้ให้บริการนี้อยู่แล้ว'], 422);
        }

        return response()->json(ChargeCode::create($data), 201);
    }

    public function update(Request $request, ChargeCode $chargeCode)
    {
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
        ]);

        $chargeCode->update($data);

        return $chargeCode;
    }

    public function destroy(ChargeCode $chargeCode)
    {
        $chargeCode->delete();

        return response()->json(['message' => 'ลบ Charge Code เรียบร้อย']);
    }
}
