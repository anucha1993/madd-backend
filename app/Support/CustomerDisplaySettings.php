<?php

namespace App\Support;

use App\Models\IntegrationSetting;

/**
 * Customer Display (the 2nd screen facing the customer at the counter, opened from Create Shipment):
 * which parts of the shipment it shows, the idle screen, branding. One set for every branch. The
 * display itself never gets cost / markup / raw carrier data — that's filtered in the browser before
 * anything is sent to it, regardless of these settings.
 */
final class CustomerDisplaySettings
{
    public const KEY = 'customer_display.settings';

    public const SECTIONS = ['recipient', 'contact', 'packages', 'billed_weight', 'quotes', 'transit', 'breakdown', 'addons', 'total', 'tracking'];

    public static function defaults(): array
    {
        return [
            'sections' => [
                'recipient' => true,      // name / address / country — for the customer to check
                'contact' => false,       // phone / e-mail — personal data, off by default
                'packages' => true,
                'billed_weight' => true,
                'quotes' => true,
                'transit' => true,
                'breakdown' => false,     // sell price lines (freight, fuel …) — total only by default
                'addons' => true,
                'total' => true,
                'tracking' => true,
            ],
            'quotes_mode' => 'all',       // all | selected | cheapest_per_carrier
            'quotes_sort' => 'price',     // price | transit
            'idle' => [
                'title' => 'ยินดีต้อนรับ',
                'subtitle' => 'บริการส่งพัสดุด่วนทั่วโลก UPS · DHL',
                'logo_url' => '',         // empty = the MADD logo
                'images' => [],           // promotion image URLs, shown in turn
                'image_seconds' => 8,
            ],
            'brand' => [
                'shop_name' => 'MADD',
                'primary_color' => '#333B42', // MADD charcoal
                'accent_color' => '#F5A623',  // MADD amber
                'font_size' => 'normal',  // normal | large
            ],
            'thank_you' => [
                'message' => 'ขอบคุณที่ใช้บริการ',
                'return_seconds' => 30,
            ],
        ];
    }

    public static function get(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        return is_array($saved) ? array_replace_recursive(self::defaults(), $saved) : self::defaults();
    }

    public static function save(array $settings): array
    {
        // Lists replace (not merge) — a removed promotion image must stay removed.
        $merged = array_replace_recursive(self::defaults(), $settings);
        $merged['idle']['images'] = array_values($settings['idle']['images'] ?? []);
        IntegrationSetting::set(self::KEY, json_encode($merged, JSON_UNESCAPED_UNICODE));

        return $merged;
    }
}
