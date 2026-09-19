<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DocumentNumberSequence;
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
            'fax' => ['nullable', 'string', 'max:50'],
            'is_head_office' => ['boolean'],
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
            'fax' => ['nullable', 'string', 'max:50'],
            'is_head_office' => ['boolean'],
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

    /**
     * The 4 Vol.No/No. numbering patterns for this branch (CASH_RECEIPT + TAX_INVOICE, each with
     * vol_no and no) — auto-creates any missing rows with the DocumentNumberService defaults so
     * the settings form always has all 4 to edit.
     */
    public function documentNumberSettings(Branch $branch)
    {
        $types = ['CASH_RECEIPT', 'TAX_INVOICE'];
        $fields = ['vol_no', 'no'];
        $defaults = ['vol_no' => '001', 'no' => '{00001}'];

        foreach ($types as $type) {
            foreach ($fields as $field) {
                DocumentNumberSequence::firstOrCreate(
                    ['branch_id' => $branch->id, 'document_type' => $type, 'field' => $field],
                    ['pattern' => $defaults[$field], 'next_number' => 1],
                );
            }
        }

        return $branch->documentNumberSequences()->get();
    }

    public function updateDocumentNumberSettings(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'sequences' => ['required', 'array'],
            'sequences.*.id' => ['required', 'integer', 'exists:document_number_sequences,id'],
            'sequences.*.pattern' => ['required', 'string', 'max:100'],
            'sequences.*.next_number' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($data['sequences'] as $row) {
            DocumentNumberSequence::where('id', $row['id'])
                ->where('branch_id', $branch->id)
                ->update(['pattern' => $row['pattern'], 'next_number' => $row['next_number']]);
        }

        return $branch->documentNumberSequences()->get();
    }
}
