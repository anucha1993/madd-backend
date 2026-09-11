<?php

namespace App\Http\Controllers;

use App\Models\AddonCategory;
use Illuminate\Http\Request;

class AddonCategoryController extends Controller
{
    public function index()
    {
        return AddonCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:addon_categories,name'],
        ]);

        $data['sort_order'] = (int) AddonCategory::max('sort_order') + 1;

        return response()->json(AddonCategory::create($data), 201);
    }

    public function update(Request $request, AddonCategory $addonCategory)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', 'unique:addon_categories,name,' . $addonCategory->id],
        ]);

        $addonCategory->update($data);

        return $addonCategory;
    }

    public function destroy(AddonCategory $addonCategory)
    {
        if ($addonCategory->items()->exists()) {
            return response()->json(['message' => 'ลบไม่ได้ ยังมีรายการ Add-on อยู่ในหมวดนี้'], 422);
        }

        $addonCategory->delete();

        return response()->json(['message' => 'ลบหมวดหมู่เรียบร้อย']);
    }
}
