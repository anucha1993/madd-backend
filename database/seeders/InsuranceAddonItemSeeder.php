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
     * - ICDV (UPS's own carrier insurance — DHL has no ICDV equivalent) — all Product Types, priced
     *   at API_COST (whatever UPS's real Ship API actually charges for Declared Value), NOT a
     *   configured percentage — a PERCENT-priced ICDV item never tells UPS about the declared value
     *   at all (see ShipmentController::store()'s `useCarrierInsurance` gate, only true for
     *   `price_type === 'API_COST'`), so the package would be sold as "insured" to the customer but
     *   never actually registered/protected with UPS. Confirmed as a real bug live 2026-09-28 via a
     *   real production booking's raw Ship API response showing zero ServiceOptionsCharges for an
     *   "insured" package. customer_types/product_types are kept per-item purely to control WHICH
     *   insurance option is eligible/shown for a given package — pricing itself is now cost-based
     *   for all three, no longer differentiated by the old 1.1%/0.4% split.
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

        // ICDV (UPS's own Declared Value insurance) — API_COST, see the doc comment above for why
        // PERCENT is wrong here. `price`/old percentage values are dropped (unused by API_COST);
        // `markup_percent` (set separately per item in /config/addon-items) now controls margin on
        // top of UPS's real charged amount instead.
        $icdvRows = [
            ['name' => 'ICDV (WI)', 'customer_types' => ['WI'], 'product_types' => ['SILVER']],
            ['name' => 'ICDV (Daily/Shop)', 'customer_types' => ['DAILY', 'CR'], 'product_types' => ['SILVER']],
            ['name' => 'ICDV (Non Silver/Other)', 'customer_types' => null, 'product_types' => ['NON_SILVER', 'OTHER']],
        ];

        foreach ($icdvRows as $row) {
            $item = AddonItem::where('addon_category_id', $category->id)->where('name', $row['name'])->first();
            $attributes = [
                'addon_category_id' => $category->id,
                'name' => $row['name'],
                'carriers' => ['UPS'],
                'customer_types' => $row['customer_types'],
                'product_types' => $row['product_types'],
                'price_type' => 'API_COST',
                'price' => null,
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
