<?php

namespace App\Http\Controllers;

use App\Support\CustomerDisplaySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'banner.image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'thank_you.message' => ['sometimes', 'nullable', 'string', 'max:200'],
            'thank_you.return_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
        ]);
        // Only http(s) images — the display page renders these URLs as <img>.
        foreach ([...($data['idle']['images'] ?? []), $data['idle']['logo_url'] ?? '', $data['banner']['image_url'] ?? ''] as $url) {
            if ($url !== '' && $url !== null && ! preg_match('#^(https?://|/api/customer-display/media/\d+$)#i', $url)) {
                return response()->json(['message' => "ลิงก์รูปต้องขึ้นต้นด้วย http:// หรือ https:// ({$url})"], 422);
            }
        }

        $settings = array_replace_recursive(CustomerDisplaySettings::get(), $data);
        if (array_key_exists('images', $data['idle'] ?? [])) {
            $settings['idle']['images'] = array_values($data['idle']['images']); // a list replaces, never merges
        }

        return response()->json(CustomerDisplaySettings::save($settings));
    }

    /** Upload the ad banner image — returns its public URL, put it in banner.image_url to use it. */
    public function uploadMedia(Request $request)
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:3072']]);
        $file = $request->file('image');
        $media = DB::table('customer_display_media')->insertGetId([
            'mime' => $file->getMimeType(),
            'data' => base64_encode(file_get_contents($file->getRealPath())),
            'size' => $file->getSize(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Host-free path: dev and production share the database, each serves it from its own API host.
        return response()->json(['url' => "/api/customer-display/media/{$media}"]);
    }

    /** Public (the display page has no login) — only images uploaded above. */
    public function media(int $id)
    {
        $media = DB::table('customer_display_media')->find($id);
        abort_unless($media, 404);

        return response(base64_decode($media->data), 200, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'public, max-age=31536000, immutable', // a new upload = a new id
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
