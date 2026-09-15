<?php

namespace Database\Seeders;

use App\Models\AddonCategory;
use App\Models\AddonItem;
use Illuminate\Database\Seeder;

class InsuranceAddonItemSeeder extends Seeder
{
    /**
     * Insurance pricing matrix:
     * - UPSC (third-party, % of Declared Value) — Silver boxes ONLY, either carrier: WI 1.1%, Daily/Shop CR 0.4%.
     * - ICDV (UPS's own insurance, % of Declared Value — DHL has no ICDV equivalent) — all Product Types:
     *   Silver: WI 1.1%, Daily/Shop CR 0.4%. Non Silver/Other: flat 0.4% for every customer type.
     * - DHL (DHL's own insurance — UPS has no equivalent here) — all Product Types, every customer
     *   type: sell price = whatever DHL's own API actually quoted for Declared Value/Insurance
     *   (API_COST), not a configured percentage.
     */
    public function run(): void
    {
        $category = AddonCategory::where('name', 'Insurance')->firstOrFail();

        // Superseded by the single API_COST "DHL (Declared Value)" row below.
        AddonItem::where('addon_category_id', $category->id)
            ->whereIn('name', ['DHL (WI)', 'DHL (Daily/Shop)', 'DHL (Non Silver/Other)'])
            ->delete();

        $percentRows = [
            // Existing UPSC items are updated to restrict them to Silver only.
            ['name' => 'UPSC (UPS, WI/Daily)', 'carriers' => ['UPS', 'DHL'], 'customer_types' => ['WI'], 'product_types' => ['SILVER'], 'price' => 1.10],
            ['name' => 'UPSC (UPS, Shop)', 'carriers' => ['UPS', 'DHL'], 'customer_types' => ['DAILY', 'CR'], 'product_types' => ['SILVER'], 'price' => 0.40],

            // ICDV — UPS only.
            ['name' => 'ICDV (WI)', 'carriers' => ['UPS'], 'customer_types' => ['WI'], 'product_types' => ['SILVER'], 'price' => 1.10],
            ['name' => 'ICDV (Daily/Shop)', 'carriers' => ['UPS'], 'customer_types' => ['DAILY', 'CR'], 'product_types' => ['SILVER'], 'price' => 0.40],
            ['name' => 'ICDV (Non Silver/Other)', 'carriers' => ['UPS'], 'customer_types' => null, 'product_types' => ['NON_SILVER', 'OTHER'], 'price' => 0.40],
        ];

        foreach ($percentRows as $row) {
            $item = AddonItem::where('addon_category_id', $category->id)->where('name', $row['name'])->first();
            $attributes = [
                'addon_category_id' => $category->id,
                'name' => $row['name'],
                'carriers' => $row['carriers'],
                'customer_types' => $row['customer_types'],
                'product_types' => $row['product_types'],
                'price_type' => 'PERCENT',
                'price' => $row['price'],
                'trigger_type' => 'MANUAL',
                'status' => true,
            ];

            if ($item) {
                $item->update($attributes);
            } else {
                AddonItem::create($attributes);
            }
        }

        // DHL's own insurance — DHL only, every Product Type/customer type, priced at DHL's real
        // quoted Declared Value/Insurance charge (API_COST) instead of a configured percentage.
        $dhlItem = AddonItem::where('addon_category_id', $category->id)->where('name', 'DHL (Declared Value)')->first();
        $dhlAttributes = [
            'addon_category_id' => $category->id,
            'name' => 'DHL (Declared Value)',
            'carriers' => ['DHL'],
            'customer_types' => null,
            'product_types' => null,
            'price_type' => 'API_COST',
            'price' => null,
            'trigger_type' => 'MANUAL',
            'status' => true,
        ];

        if ($dhlItem) {
            $dhlItem->update($dhlAttributes);
        } else {
            AddonItem::create($dhlAttributes);
        }
    }
}
