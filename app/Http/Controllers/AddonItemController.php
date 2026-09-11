<?php

namespace App\Http\Controllers;

use App\Models\AddonItem;
use Illuminate\Http\Request;

class AddonItemController extends Controller
{
    public function index(Request $request)
    {
        $query = AddonItem::with('category');

        if ($request->filled('addon_category_id')) {
            $query->where('addon_category_id', $request->integer('addon_category_id'));
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'addon_category_id' => ['required', 'integer', 'exists:addon_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'carriers' => ['required', 'array', 'min:1'],
            'carriers.*' => ['required', 'string', 'in:UPS,DHL'],
            'price_type' => ['required', 'in:FIXED,MANUAL'],
            'price' => ['nullable', 'required_if:price_type,FIXED', 'numeric', 'min:0'],
            'trigger_type' => ['required', 'in:MANUAL,AUTO'],
            'status' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(AddonItem::create($data)->load('category'), 201);
    }

    public function update(Request $request, AddonItem $addonItem)
    {
        $data = $request->validate([
            'addon_category_id' => ['sometimes', 'required', 'integer', 'exists:addon_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'carriers' => ['sometimes', 'required', 'array', 'min:1'],
            'carriers.*' => ['required', 'string', 'in:UPS,DHL'],
            'price_type' => ['sometimes', 'required', 'in:FIXED,MANUAL'],
            'price' => ['nullable', 'required_if:price_type,FIXED', 'numeric', 'min:0'],
            'trigger_type' => ['sometimes', 'required', 'in:MANUAL,AUTO'],
            'status' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $addonItem->update($data);

        return $addonItem->load('category');
    }

    public function destroy(AddonItem $addonItem)
    {
        $addonItem->delete();

        return response()->json(['message' => 'ลบรายการ Add-on เรียบร้อย']);
    }
}
