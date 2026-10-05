<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\ZonePrice;
use App\Support\SimpleSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff's own price per charge code per manual zone (countries.ups_zone / dhl_zone), plus
 * single-country rows that differ from the rest of their zone. Used as {ZONE_PRICE} in Fixed
 * Charges / Markup Rules formulas.
 */
class ZonePriceController extends Controller
{
    private const HEADER = ['carrier', 'charge_code', 'zone', 'country_iso2', 'price', 'note'];

    public function index(Request $request)
    {
        return ZonePrice::query()
            ->when($request->filled('carrier'), fn ($q) => $q->where('carrier', strtoupper($request->string('carrier'))))
            ->orderBy('carrier')->orderBy('charge_code')
            // Zone-wide rows first, then the per-country exceptions.
            ->orderByRaw('CASE WHEN country_iso2 IS NULL THEN 0 ELSE 1 END')
            ->orderBy('zone')->orderBy('country_iso2')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->assertUnique($data);

        return response()->json(ZonePrice::create($data), 201);
    }

    public function update(Request $request, ZonePrice $zonePrice)
    {
        $data = $this->validated($request);
        $this->assertUnique($data, $zonePrice->id);
        $zonePrice->update($data);

        return $zonePrice;
    }

    public function destroy(ZonePrice $zonePrice)
    {
        $zonePrice->delete();

        return response()->json(['message' => 'ลบราคาเรียบร้อย']);
    }

    public function export()
    {
        $rows = $this->index(new Request())->map(fn (ZonePrice $p) => [$p->carrier, $p->charge_code, $p->zone, $p->country_iso2, $p->price, $p->note])->all();

        return SimpleSheet::download('zone-prices.xlsx', 'Zone Prices', self::HEADER, $rows, [
            'วิธีใช้ — ห้ามแก้หัวคอลัมน์แถวแรก · อัปโหลดแล้ว "แทนที่ราคาเดิมทั้งหมด" ของ Carrier ที่อยู่ในไฟล์',
            'carrier: UPS หรือ DHL',
            'charge_code: Charge Code ที่จะใช้ราคานี้ เช่น 190, 434 (ตรงกับ Code ใน Fixed Charges / Markup Rules)',
            'zone: Zone ที่ตั้งไว้ในหน้า Countries (ups_zone / dhl_zone) — ใส่ zone เพื่อใช้ราคากับทุกประเทศใน Zone',
            'country_iso2: ใส่แทน zone เมื่อประเทศนี้ราคาไม่เหมือนประเทศอื่นใน Zone เดียวกัน (เช่น TW) — แถวประเทศชนะแถว Zone',
            'ใส่อย่างใดอย่างหนึ่งระหว่าง zone หรือ country_iso2 เท่านั้น · price: ราคา THB · note: หมายเหตุ (ไม่บังคับ)',
            'ใช้ในสูตร: {ZONE_PRICE} = ราคาของ Code ที่กำลังคำนวณ เช่น Fixed Charge ของ 434 ใส่สูตร {ZONE_PRICE} หรือ {ZONE_PRICE} * {W}',
            'ถ้าปลายทางไม่มีราคาที่ตั้งไว้ ระบบใช้ราคาจาก API ของ Carrier ตามเดิม',
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);

        [$header, $rows] = SimpleSheet::read($request->file('file')->getRealPath());
        if ($missing = array_diff(['carrier', 'charge_code', 'price'], $header)) {
            return response()->json(['message' => 'ไม่พบคอลัมน์: '.implode(', ', $missing).' — กรุณาใช้ไฟล์ที่ดาวน์โหลดจากปุ่ม Download'], 422);
        }

        $knownIso2 = Country::pluck('iso2')->map(fn ($iso) => strtoupper($iso))->flip();
        $clean = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $item = [
                'carrier' => strtoupper(trim((string) ($row['carrier'] ?? ''))),
                'charge_code' => $this->text($row['charge_code'] ?? null),
                'zone' => $this->zone($row['zone'] ?? null),
                'country_iso2' => ($iso = strtoupper(trim((string) ($row['country_iso2'] ?? '')))) === '' ? null : $iso,
                'price' => $row['price'] ?? null,
                'note' => $this->text($row['note'] ?? null),
            ];
            $problem = match (true) {
                ! in_array($item['carrier'], ZonePrice::CARRIERS, true) => 'carrier ต้องเป็น UPS หรือ DHL',
                $item['charge_code'] === null => 'ไม่มี charge_code',
                ($item['zone'] === null) === ($item['country_iso2'] === null) => 'ใส่ zone หรือ country_iso2 อย่างใดอย่างหนึ่ง',
                $item['country_iso2'] !== null && ! $knownIso2->has($item['country_iso2']) => "ไม่รู้จักประเทศ {$item['country_iso2']}",
                ! is_numeric($item['price']) || (float) $item['price'] < 0 => 'price ต้องเป็นตัวเลข ≥ 0',
                default => null,
            };
            if ($problem) {
                $errors[] = "แถว {$line}: {$problem}";

                continue;
            }
            $item['price'] = round((float) $item['price'], 2);
            $key = implode('|', [$item['carrier'], $item['charge_code'], $item['zone'], $item['country_iso2']]);
            $clean[$key] = $item; // a duplicate row later in the file wins
        }

        if ($errors) {
            return response()->json(['message' => 'นำเข้าไม่สำเร็จ — '.implode(' · ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' …' : ''), 'errors' => $errors], 422);
        }
        if (! $clean) {
            return response()->json(['message' => 'ไม่พบข้อมูลที่นำเข้าได้ในไฟล์'], 422);
        }

        $carriers = array_values(array_unique(array_column($clean, 'carrier')));
        DB::transaction(function () use ($clean, $carriers) {
            ZonePrice::whereIn('carrier', $carriers)->delete();
            $now = now();
            foreach (array_chunk(array_values($clean), 200) as $chunk) {
                ZonePrice::insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $chunk));
            }
        });

        return response()->json([
            'message' => 'นำเข้าราคาสำเร็จ '.count($clean).' รายการ (แทนที่ราคาเดิมของ '.implode(', ', $carriers).')',
            'imported' => count($clean),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'carrier' => ['required', Rule::in(ZonePrice::CARRIERS)],
            'charge_code' => ['required', 'string', 'max:50'],
            'zone' => ['nullable', 'required_without:country_iso2', 'prohibits:country_iso2', 'string', 'max:20'],
            'country_iso2' => ['nullable', 'required_without:zone', 'string', 'size:2', 'exists:countries,iso2'],
            'price' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $data['charge_code'] = trim($data['charge_code']);
        $data['zone'] = $this->zone($data['zone'] ?? null);
        $data['country_iso2'] = isset($data['country_iso2']) ? strtoupper($data['country_iso2']) : null;

        return $data;
    }

    private function assertUnique(array $data, ?int $ignoreId = null): void
    {
        $exists = ZonePrice::where('carrier', $data['carrier'])->where('charge_code', $data['charge_code'])
            ->where(fn ($q) => $data['zone'] === null ? $q->whereNull('zone') : $q->where('zone', $data['zone']))
            ->where(fn ($q) => $data['country_iso2'] === null ? $q->whereNull('country_iso2') : $q->where('country_iso2', $data['country_iso2']))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['zone' => 'มีราคาของ Code / Zone (หรือประเทศ) นี้อยู่แล้ว']);
        }
    }

    private function text($value): ?string
    {
        $value = trim((string) $value);
        if (is_numeric($value) && (float) $value == (int) $value) {
            $value = (string) (int) $value; // Excel "190.0" → "190"
        }

        return $value === '' ? null : $value;
    }

    private function zone($value): ?string
    {
        $zone = $this->text($value);

        return $zone === null ? null : mb_substr(strtoupper($zone), 0, 20);
    }
}
