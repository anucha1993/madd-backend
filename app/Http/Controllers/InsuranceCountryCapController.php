<?php

namespace App\Http\Controllers;

use App\Models\InsuranceCountryCap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsuranceCountryCapController extends Controller
{
    public function index(Request $request)
    {
        $query = InsuranceCountryCap::query();

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($sub) use ($search) {
                $sub->where('country_name', 'like', "%{$search}%")
                    ->orWhere('country_code', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('country_name')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'country_name' => ['required', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'ups_max_value' => ['nullable', 'numeric', 'min:0'],
            'dhl_max_value' => ['nullable', 'numeric', 'min:0'],
            'ups_max_declared' => ['nullable', 'numeric', 'min:0'],
            'dhl_max_declared' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(InsuranceCountryCap::create($data), 201);
    }

    public function show(InsuranceCountryCap $insuranceCountryCap)
    {
        return $insuranceCountryCap;
    }

    /**
     * Single exact-match lookup by country_code, used by Create Shipment to apply the
     * destination country's coverage cap / sanction note when selling insurance.
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'country_code' => ['required', 'string', 'max:5'],
        ]);

        $cap = InsuranceCountryCap::whereRaw('LOWER(country_code) = ?', [strtolower($request->string('country_code'))])->first();

        return response()->json($cap);
    }

    public function update(Request $request, InsuranceCountryCap $insuranceCountryCap)
    {
        $data = $request->validate([
            'country_name' => ['sometimes', 'required', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'ups_max_value' => ['nullable', 'numeric', 'min:0'],
            'dhl_max_value' => ['nullable', 'numeric', 'min:0'],
            'ups_max_declared' => ['nullable', 'numeric', 'min:0'],
            'dhl_max_declared' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $insuranceCountryCap->update($data);

        return $insuranceCountryCap;
    }

    public function destroy(InsuranceCountryCap $insuranceCountryCap)
    {
        $insuranceCountryCap->delete();

        return response()->json(['message' => 'ลบข้อมูลประเทศเรียบร้อย']);
    }

    /**
     * Bulk replace ALL rows from an uploaded CSV file (full refresh, e.g. yearly rate update).
     * Expected header (any order): country_name, country_code, ups_max_value, dhl_max_value,
     * ups_max_declared, dhl_max_declared, note. Country codes are not unique in the source data
     * (several small territories share a parent country's code), so this replaces the whole
     * table rather than upserting by code.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);

            return response()->json(['message' => 'ไฟล์ CSV ว่างเปล่าหรืออ่านไม่ได้'], 422);
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $allowed = [
            'country_name', 'country_code', 'ups_max_value', 'dhl_max_value',
            'ups_max_declared', 'dhl_max_declared', 'note',
        ];

        $now = now();
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $line);
            $row = array_intersect_key($row, array_flip($allowed));

            if (empty($row['country_name'])) {
                continue;
            }

            foreach (['ups_max_value', 'dhl_max_value', 'ups_max_declared', 'dhl_max_declared'] as $numericField) {
                $row[$numericField] = isset($row[$numericField]) && $row[$numericField] !== ''
                    ? (float) $row[$numericField]
                    : null;
            }
            $row['country_code'] = ($row['country_code'] ?? '') !== '' ? $row['country_code'] : null;
            $row['note'] = ($row['note'] ?? '') !== '' ? $row['note'] : null;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $rows[] = $row;
        }
        fclose($handle);

        if (empty($rows)) {
            return response()->json(['message' => 'ไม่พบข้อมูลที่นำเข้าได้ในไฟล์'], 422);
        }

        DB::transaction(function () use ($rows) {
            InsuranceCountryCap::query()->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('insurance_country_caps')->insert($chunk);
            }
        });

        return response()->json(['message' => 'นำเข้าข้อมูลสำเร็จ '.count($rows).' รายการ (แทนที่ข้อมูลเดิมทั้งหมด)', 'imported' => count($rows)]);
    }
}
