<?php

namespace App\Http\Controllers;

use App\Models\ThaiSubdistrict;
use Illuminate\Http\Request;

class ThaiSubdistrictController extends Controller
{
    public function index(Request $request)
    {
        $query = ThaiSubdistrict::query();

        if ($request->filled('zip_code')) {
            $query->where('zip_code', 'like', $request->string('zip_code') . '%');
        }

        if ($request->filled('q')) {
            $search = $request->string('q');
            $searchNoSpace = str_replace(' ', '', $search);
            $query->where(function ($sub) use ($search, $searchNoSpace) {
                $sub->where('name_th', 'like', "%{$search}%")
                    ->orWhere('district_name_th', 'like', "%{$search}%")
                    ->orWhere('province_name_th', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('district_name_en', 'like', "%{$search}%")
                    ->orWhere('province_name_en', 'like', "%{$search}%")
                    // also match English names with spaces removed, e.g. "wangthong" -> "Wang Thong"
                    ->orWhereRaw("REPLACE(name_en, ' ', '') LIKE ?", ["%{$searchNoSpace}%"])
                    ->orWhereRaw("REPLACE(district_name_en, ' ', '') LIKE ?", ["%{$searchNoSpace}%"])
                    ->orWhereRaw("REPLACE(province_name_en, ' ', '') LIKE ?", ["%{$searchNoSpace}%"]);
            });
        }

        if ($request->filled('region')) {
            $query->where('region', $request->string('region'));
        }

        return $query->orderBy('zip_code')->orderBy('tambon_id')->paginate($request->integer('per_page', 20));
    }

    /**
     * Distinct region list for the filter dropdown.
     */
    public function regions()
    {
        return ThaiSubdistrict::query()
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tambon_id' => ['required', 'integer', 'unique:thai_subdistricts,tambon_id'],
            'name_th' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', 'integer'],
            'district_name_th' => ['required', 'string', 'max:255'],
            'district_name_en' => ['nullable', 'string', 'max:255'],
            'province_id' => ['required', 'integer'],
            'province_name_th' => ['required', 'string', 'max:255'],
            'province_name_en' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['required', 'string', 'max:10'],
            'zip_code_all' => ['nullable', 'string', 'max:50'],
        ]);

        return response()->json(ThaiSubdistrict::create($data), 201);
    }

    public function show(ThaiSubdistrict $thaiSubdistrict)
    {
        return $thaiSubdistrict;
    }

    public function update(Request $request, ThaiSubdistrict $thaiSubdistrict)
    {
        $data = $request->validate([
            'name_th' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'district_name_th' => ['sometimes', 'required', 'string', 'max:255'],
            'district_name_en' => ['nullable', 'string', 'max:255'],
            'province_name_th' => ['sometimes', 'required', 'string', 'max:255'],
            'province_name_en' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['sometimes', 'required', 'string', 'max:10'],
            'zip_code_all' => ['nullable', 'string', 'max:50'],
        ]);

        $thaiSubdistrict->update($data);

        return $thaiSubdistrict;
    }

    public function destroy(ThaiSubdistrict $thaiSubdistrict)
    {
        $thaiSubdistrict->delete();

        return response()->json(['message' => 'ลบข้อมูลตำบล/แขวงเรียบร้อย']);
    }

    /**
     * Look up every subdistrict/district/province sharing a given zip code —
     * the primary reference point for editing this dataset.
     */
    public function byZipCode(string $zipCode)
    {
        return ThaiSubdistrict::where('zip_code', $zipCode)->orderBy('tambon_id')->get();
    }
}
