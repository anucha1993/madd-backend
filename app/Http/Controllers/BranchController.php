<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        return Branch::withCount(['users', 'carrierAccounts'])->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:branches,code'],
            'tax_id' => ['required', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['boolean'],
        ]);

        return response()->json(Branch::create($data), 201);
    }

    public function show(Branch $branch)
    {
        return $branch->load('users');
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:branches,code,' . $branch->id],
            'tax_id' => ['sometimes', 'required', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['boolean'],
        ]);

        $branch->update($data);

        return $branch;
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();

        return response()->json(['message' => 'ลบสาขาเรียบร้อย']);
    }
}
