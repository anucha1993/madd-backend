<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Support\SimpleSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff's own zone per carrier for each country (countries.ups_zone / dhl_zone) — Excel download
 * (= template + current data) and upload. Prices per zone live in ZonePriceController.
 */
class CountryZoneController extends Controller
{
    private const HEADER = ['iso2', 'country', 'ups_zone', 'dhl_zone'];

    public function update(Request $request, Country $country)
    {
        $data = $request->validate([
            'ups_zone' => ['nullable', 'string', 'max:20'],
            'dhl_zone' => ['nullable', 'string', 'max:20'],
        ]);

        $country->update(array_map(fn ($zone) => $this->normalizeZone($zone), $data));

        return $country;
    }

    public function export()
    {
        $rows = Country::orderBy('name')->get()->map(fn (Country $c) => [$c->iso2, $c->name, $c->ups_zone, $c->dhl_zone])->all();

        return SimpleSheet::download('country-zones.xlsx', 'Zones', self::HEADER, $rows, [
            'วิธีใช้ — ห้ามแก้หัวคอลัมน์แถวแรก',
            'iso2: รหัสประเทศ 2 ตัว (ใช้จับคู่ประเทศ ห้ามแก้) · country: ชื่อประเทศ (ไว้ดูเฉยๆ ไม่ถูกนำเข้า)',
            'ups_zone / dhl_zone: Zone ที่ตั้งเอง เช่น 1, 2, 3 หรือ A, B — เว้นว่าง = ไม่มี Zone',
            'ลบคอลัมน์ ups_zone หรือ dhl_zone ทิ้งได้ถ้าจะอัปเดตแค่ Carrier เดียว (คอลัมน์ที่ไม่มีจะไม่ถูกแก้)',
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);

        [$header, $rows] = SimpleSheet::read($request->file('file')->getRealPath());
        if (! in_array('iso2', $header, true)) {
            return response()->json(['message' => 'ไม่พบคอลัมน์ iso2 — กรุณาใช้ไฟล์ที่ดาวน์โหลดจากปุ่ม Download Zone'], 422);
        }
        $columns = array_values(array_intersect(['ups_zone', 'dhl_zone'], $header));
        if (! $columns) {
            return response()->json(['message' => 'ไม่พบคอลัมน์ ups_zone หรือ dhl_zone'], 422);
        }

        $countries = Country::all()->keyBy(fn (Country $c) => strtoupper($c->iso2));
        $updated = 0;
        $unknown = [];

        DB::transaction(function () use ($rows, $columns, $countries, &$updated, &$unknown) {
            foreach ($rows as $row) {
                $iso2 = strtoupper(trim((string) ($row['iso2'] ?? '')));
                if ($iso2 === '') {
                    continue;
                }
                $country = $countries->get($iso2);
                if (! $country) {
                    $unknown[] = $iso2;

                    continue;
                }
                $changes = [];
                foreach ($columns as $column) {
                    $changes[$column] = $this->normalizeZone($row[$column] ?? null);
                }
                $country->fill($changes);
                if ($country->isDirty()) {
                    $country->save();
                    $updated++;
                }
            }
        });

        $message = "อัปเดต Zone สำเร็จ {$updated} ประเทศ";
        if ($unknown) {
            $message .= ' · ไม่พบรหัสประเทศ: '.implode(', ', array_slice($unknown, 0, 10)).(count($unknown) > 10 ? ' …' : '');
        }

        return response()->json(['message' => $message, 'updated' => $updated, 'unknown' => $unknown]);
    }

    private function normalizeZone($zone): ?string
    {
        $zone = trim((string) $zone);
        // Excel turns "1" into 1.0 — keep "1", not "1.0".
        if (is_numeric($zone) && (float) $zone == (int) $zone) {
            $zone = (string) (int) $zone;
        }

        return $zone === '' ? null : mb_substr(strtoupper($zone), 0, 20);
    }
}
