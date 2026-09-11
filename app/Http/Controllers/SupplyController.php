<?php

namespace App\Http\Controllers;

use App\Models\Supply;
use Illuminate\Http\Request;

class SupplyController extends Controller
{
    public function index()
    {
        return Supply::orderBy('name')->get();
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
            'cost_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['boolean'],
        ]);

        return response()->json(Supply::create($data), 201);
    }

    public function show(Supply $supply)
    {
        return $supply;
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
            'cost_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['boolean'],
        ]);

        $supply->update($data);

        return $supply;
    }

    public function destroy(Supply $supply)
    {
        $supply->delete();

        return response()->json(['message' => 'ลบข้อมูลวัสดุห่อเรียบร้อย']);
    }
}
