<?php

namespace App\Http\Controllers;

use App\Models\HsCode;
use App\Services\HsCodeImporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HS / HTS code list (/config/hs-codes) and the HS Code lookup of the Commercial Invoice on
 * /shipment/create (`?lookup=1`: active codes only, top 20).
 */
class HsCodeController extends Controller
{
    public function index(Request $request)
    {
        $query = HsCode::query()->orderBy('code')->orderBy('description');

        if ($q = trim((string) $request->query('q'))) {
            $digits = HsCodeImporter::normalizeCode($q);
            $query->where(function ($w) use ($q, $digits) {
                $w->where('description', 'like', "%{$q}%");
                if (strlen($digits) >= 2) {
                    $w->orWhere('code', 'like', "{$digits}%");
                }
            });
        }

        if ($request->boolean('lookup')) {
            return response()->json($query->where('status', true)->limit(20)->get());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->boolean('status'));
        }

        return response()->json($query->paginate(min(200, max(20, (int) $request->query('per_page', 50)))));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(HsCode::create($data), 201);
    }

    public function update(Request $request, HsCode $hsCode)
    {
        $hsCode->update($this->validated($request, $hsCode));

        return response()->json($hsCode);
    }

    public function destroy(HsCode $hsCode)
    {
        $hsCode->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** Upload an Excel file (CPSC HTS list layout, or "HS Code | Description" columns). */
    public function import(Request $request, HsCodeImporter $importer)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $file = $request->file('file');
        $counts = $importer->import($file->getRealPath(), $file->getClientOriginalName());
        if ($counts['created'] + $counts['updated'] === 0) {
            return response()->json(['error' => 'ไม่พบรายการ HS Code ในไฟล์ — ต้องมีคอลัมน์ "HS Code" (หรือ "HTS Code (10-digit)") และ "Description" (หรือ "Product Category")'], 422);
        }

        return response()->json($counts);
    }

    private function validated(Request $request, ?HsCode $current = null): array
    {
        $request->merge(['code' => HsCodeImporter::normalizeCode((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:20', Rule::unique('hs_codes')->where('description', $request->input('description'))->ignore($current?->id)],
            'description' => ['required', 'string', 'max:255'],
            'chapter' => ['nullable', 'string', 'max:50'],
            'status' => ['boolean'],
        ], ['code.unique' => 'มี HS Code นี้กับคำอธิบายเดียวกันอยู่แล้ว', 'code.min' => 'HS Code ต้องมีอย่างน้อย 6 หลัก']);

        return $data + ['code_formatted' => HsCodeImporter::format($data['code']), 'source' => $current?->source ?? 'Manual'];
    }
}
