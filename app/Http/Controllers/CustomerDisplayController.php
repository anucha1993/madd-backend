<?php

namespace App\Http\Controllers;

use App\Support\CustomerDisplaySettings;
use Illuminate\Http\Request;

/** /config/customer-display — what the customer-facing 2nd screen shows (see CustomerDisplaySettings). */
class CustomerDisplayController extends Controller
{
    public function show()
    {
        return response()->json(CustomerDisplaySettings::get());
    }

    public function update(Request $request)
    {
        $sections = array_map(fn ($s) => "sections.{$s}", CustomerDisplaySettings::SECTIONS);
        $data = $request->validate(array_fill_keys($sections, ['sometimes', 'boolean']) + [
            'quotes_mode' => ['sometimes', 'in:all,selected,cheapest_per_carrier'],
            'quotes_sort' => ['sometimes', 'in:price,transit'],
            'idle.title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'idle.subtitle' => ['sometimes', 'nullable', 'string', 'max:200'],
            'idle.logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'idle.images' => ['sometimes', 'array', 'max:20'],
            'idle.images.*' => ['string', 'max:500'],
            'idle.image_seconds' => ['sometimes', 'integer', 'min:3', 'max:120'],
            'brand.shop_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'brand.primary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand.accent_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand.font_size' => ['sometimes', 'in:normal,large'],
            'thank_you.message' => ['sometimes', 'nullable', 'string', 'max:200'],
            'thank_you.return_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
        ]);
        // Only http(s) images — the display page renders these URLs as <img>.
        foreach ([...($data['idle']['images'] ?? []), $data['idle']['logo_url'] ?? ''] as $url) {
            if ($url !== '' && $url !== null && ! preg_match('#^https?://#i', $url)) {
                return response()->json(['message' => "ลิงก์รูปต้องขึ้นต้นด้วย http:// หรือ https:// ({$url})"], 422);
            }
        }

        $settings = array_replace_recursive(CustomerDisplaySettings::get(), $data);
        if (array_key_exists('images', $data['idle'] ?? [])) {
            $settings['idle']['images'] = array_values($data['idle']['images']); // a list replaces, never merges
        }

        return response()->json(CustomerDisplaySettings::save($settings));
    }
}
