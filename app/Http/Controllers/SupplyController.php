<?php

namespace App\Http\Controllers;

use App\Models\Supply;
use Illuminate\Http\Request;

class SupplyController extends Controller
{
    private const MAX_FEATURED = 6;

    public function index()
    {
        return Supply::with('weightBand')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'icon_url' => ['nullable', 'url', 'max:2048'],
            'weight_band_id' => ['nullable', 'integer', 'exists:product_weight_bands,id'],
            'is_featured' => ['boolean'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['boolean'],
        ]);

        if (! empty($data['is_featured']) && $this->featuredCount() >= self::MAX_FEATURED) {
            return response()->json(['message' => 'ปักหมุด Common Sizes guide ได้สูงสุด ' . self::MAX_FEATURED . ' รายการ'], 422);
        }

        return response()->json(Supply::create($data)->load('weightBand'), 201);
    }

    public function show(Supply $supply)
    {
        return $supply->load('weightBand');
    }

    public function update(Request $request, Supply $supply)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'icon_url' => ['nullable', 'url', 'max:2048'],
            'weight_band_id' => ['nullable', 'integer', 'exists:product_weight_bands,id'],
            'is_featured' => ['boolean'],
            'cost_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['boolean'],
        ]);

        if (! empty($data['is_featured']) && ! $supply->is_featured && $this->featuredCount() >= self::MAX_FEATURED) {
            return response()->json(['message' => 'ปักหมุด Common Sizes guide ได้สูงสุด ' . self::MAX_FEATURED . ' รายการ'], 422);
        }

        $supply->update($data);

        return $supply->load('weightBand');
    }

    public function destroy(Supply $supply)
    {
        $supply->delete();

        return response()->json(['message' => 'ลบข้อมูลวัสดุห่อเรียบร้อย']);
    }

    private function featuredCount(): int
    {
        return Supply::where('is_featured', true)->count();
    }
}
