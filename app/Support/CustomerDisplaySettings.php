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

    // No transit time — estimated delivery days are never shown to the customer.
    public const SECTIONS = ['sender', 'recipient', 'contact', 'packages', 'billed_weight', 'quotes', 'breakdown', 'addons', 'total', 'tracking'];

    public static function defaults(): array
    {
        return [
            'sections' => [
                'sender' => true,         // the customer sending — name / address
                'recipient' => true,      // name / address / country — for the customer to check
                'contact' => false,       // phone / e-mail — personal data, off by default
                'packages' => true,
                'billed_weight' => true,
                'quotes' => true,
                'breakdown' => false,     // sell price lines (freight, fuel …) — total only by default
                'addons' => true,
                'total' => true,
                'tracking' => true,
            ],
            'quotes_mode' => 'all',       // all | selected | cheapest_per_carrier
            'quotes_sort' => 'price',     // price | transit
            'idle' => [
                'title' => 'Welcome',
                'subtitle' => 'Worldwide express delivery · UPS · DHL',
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
            // One ad banner on the transaction screen (uploaded image or a link); empty = no banner.
            'banner' => [
                'image_url' => '',
            ],
            'thank_you' => [
                'message' => 'Thank you for shipping with us',
                'return_seconds' => 30,
            ],
        ];
    }

    public static function get(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        $settings = is_array($saved) ? array_replace_recursive(self::defaults(), $saved) : self::defaults();
        unset($settings['sections']['transit']); // never shown any more (see SECTIONS)

        return $settings;
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
