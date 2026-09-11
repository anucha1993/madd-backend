<?php

namespace Database\Seeders;

use App\Models\AddonCategory;
use App\Models\AddonItem;
use Illuminate\Database\Seeder;

class AddonItemSeeder extends Seeder
{
    /**
     * Real values from the legacy pricing spreadsheet (Metal box / Form / OT / Other section).
     */
    public function run(): void
    {
        $metalBox = AddonCategory::where('name', 'Metal Box')->firstOrFail();
        $formOt = AddonCategory::where('name', 'Form / OT Fee')->firstOrFail();
        $other = AddonCategory::where('name', 'Other')->firstOrFail();

        $rows = [
            // Metal box variants — price entered per case, no fixed default in the spec.
            ['category' => $metalBox, 'name' => 'Metal Box (10C)', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null],
            ['category' => $metalBox, 'name' => 'Metal Box (25C)', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null],
            ['category' => $metalBox, 'name' => 'Metal Box (SS)', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null],
            ['category' => $metalBox, 'name' => 'Metal Box (90D)', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null],
            ['category' => $metalBox, 'name' => 'Metal Box (PIZZA)', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null],
            ['category' => $metalBox, 'name' => 'Metal Box (U2)', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null],

            // Form / OT — real fixed per-carrier fees (DHL has no OT service).
            ['category' => $formOt, 'name' => 'Form Fee', 'carriers' => ['UPS'], 'price_type' => 'FIXED', 'price' => 535, 'note' => 'ค่าทำฟอร์มเอกสารสำหรับส่งออก'],
            ['category' => $formOt, 'name' => 'OT Fee', 'carriers' => ['UPS'], 'price_type' => 'FIXED', 'price' => 214, 'note' => 'คิดค่าโอทีลูกค้า กรณีทำฟอร์มหลังเลิกงานของศุลกากร'],
            ['category' => $formOt, 'name' => 'Form Fee', 'carriers' => ['DHL'], 'price_type' => 'FIXED', 'price' => 343, 'note' => 'ค่าทำฟอร์มเอกสารสำหรับส่งออก'],

            // Other — price entered per shipment.
            ['category' => $other, 'name' => 'Bubble/Foam', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null, 'note' => 'บับเบิ้ลกันกระแทก'],
            ['category' => $other, 'name' => 'Others', 'carriers' => ['UPS', 'DHL'], 'price_type' => 'MANUAL', 'price' => null, 'note' => 'อื่นๆ (พิมพ์ Remark เพิ่มได้)'],
        ];

        foreach ($rows as $row) {
            $existing = AddonItem::where('addon_category_id', $row['category']->id)
                ->where('name', $row['name'])
                ->get()
                ->first(fn ($item) => $item->carriers === $row['carriers']);

            $attributes = [
                'addon_category_id' => $row['category']->id,
                'name' => $row['name'],
                'carriers' => $row['carriers'],
                'price_type' => $row['price_type'],
                'price' => $row['price'] ?? null,
                'trigger_type' => 'MANUAL',
                'status' => true,
                'note' => $row['note'] ?? null,
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                AddonItem::create($attributes);
            }
        }
    }
}
