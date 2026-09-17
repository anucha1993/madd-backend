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
            'customer_types' => ['nullable', 'array'],
            'customer_types.*' => ['required', 'string', 'max:20'],
            // Which Create Shipment box Product Type(s) this item applies to — null/empty = all.
            'product_types' => ['nullable', 'array'],
            'product_types.*' => ['required', 'string', 'in:SILVER,NON_SILVER,OTHER'],
            'price_type' => ['required', 'in:FIXED,MANUAL,PERCENT,API_COST'],
            'price' => ['nullable', 'required_if:price_type,FIXED,PERCENT', 'numeric', 'min:0'],
            // Extra % on top of whatever price_type already computes — available regardless of
            // price_type (e.g. still add +2% on top of a PERCENT-of-declared-value or API_COST price).
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
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
            'customer_types' => ['nullable', 'array'],
            'customer_types.*' => ['required', 'string', 'max:20'],
            'product_types' => ['nullable', 'array'],
            'product_types.*' => ['required', 'string', 'in:SILVER,NON_SILVER,OTHER'],
            'price_type' => ['sometimes', 'required', 'in:FIXED,MANUAL,PERCENT,API_COST'],
            'price' => ['nullable', 'required_if:price_type,FIXED,PERCENT', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
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
