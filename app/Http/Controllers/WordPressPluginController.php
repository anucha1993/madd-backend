<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * WordPress plugins for the Public API, kept as source in resources/wordpress-plugins/<slug>
 * and zipped on download (Config › Public API). They hold no secrets — the site admin enters
 * the API URL / key after installing — but downloading still needs `config.api_clients`.
 */
class WordPressPluginController extends Controller
{
    private const PLUGINS = [
        'madd-tracking' => 'ฟอร์มติดตามพัสดุ [madd_tracking]',
        'madd-rate-quote' => 'ฟอร์มเช็คราคาค่าส่ง [madd_rate_quote] — ใช้คู่กับ MADD Tracking ได้',
    ];

    public function index()
    {
        return response()->json(collect(self::PLUGINS)->map(function (string $summary, string $slug) {
            $header = $this->header($slug);

            return [
                'slug' => $slug,
                'name' => $header['Plugin Name'] ?? $slug,
                'version' => $header['Version'] ?? null,
                'summary' => $summary,
                'updated_at' => date(DATE_ATOM, collect(File::allFiles($this->path($slug)))->max(fn ($f) => $f->getMTime())),
            ];
        })->values());
    }

    public function download(string $slug)
    {
        abort_unless(isset(self::PLUGINS[$slug]), 404);
        $version = $this->header($slug)['Version'] ?? 'latest';

        $zipPath = tempnam(sys_get_temp_dir(), 'wp-plugin-');
        $zip = new ZipArchive();
        abort_unless($zip->open($zipPath, ZipArchive::OVERWRITE) === true, 500, 'สร้างไฟล์ zip ไม่สำเร็จ');
        // WordPress expects a single top-level folder named after the plugin.
        foreach (File::allFiles($this->path($slug)) as $file) {
            $zip->addFile($file->getPathname(), $slug.'/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $zip->close();

        app(\App\Services\AuditLogger::class)->accessed('downloaded', 'WordPressPlugin', ['version' => $version], $slug);

        return response()->download($zipPath, "{$slug}-{$version}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function path(string $slug): string
    {
        return resource_path("wordpress-plugins/{$slug}");
    }

    /** @return array<string, string> the "Key: value" lines of the plugin's header comment */
    private function header(string $slug): array
    {
        $contents = File::get($this->path($slug)."/{$slug}.php");
        preg_match_all('/^\s*\*\s*([A-Za-z ]+):\s*(.+)$/m', substr($contents, 0, 2000), $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($row) => [trim($row[1]) => trim($row[2])])->all();
    }
}
