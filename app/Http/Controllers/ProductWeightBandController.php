<?php

namespace App\Http\Controllers;

use App\Models\ProductWeightBand;
use Illuminate\Http\Request;

class ProductWeightBandController extends Controller
{
    public function index()
    {
        return ProductWeightBand::orderBy('sort_order')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:product_weight_bands,code'],
            'label' => ['required', 'string', 'max:100'],
            'package_type' => ['required', 'in:box,document'],
            'min_weight' => ['nullable', 'numeric', 'min:0'],
            'max_weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['boolean'],
        ]);

        $data['sort_order'] = (int) ProductWeightBand::max('sort_order') + 1;

        return response()->json(ProductWeightBand::create($data), 201);
    }

    public function update(Request $request, ProductWeightBand $productWeightBand)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:product_weight_bands,code,' . $productWeightBand->id],
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'package_type' => ['sometimes', 'required', 'in:box,document'],
            'min_weight' => ['nullable', 'numeric', 'min:0'],
            'max_weight' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['boolean'],
        ]);

        $productWeightBand->update($data);

        return $productWeightBand;
    }

    public function destroy(ProductWeightBand $productWeightBand)
    {
        $productWeightBand->delete();

        return response()->json(['message' => 'ลบช่วงน้ำหนักเรียบร้อย']);
    }
}
