<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSetting;
use App\Services\AuditLogger;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;

/**
 * Custom names for the left-sidebar menu — one set for everyone, renamed only by a Super Admin
 * (the pencil in the sidebar). Keys are the menu's own ids from the frontend's lib/nav.ts (a page
 * href, or "section:<label>" for a group); a key without a name shows the built-in label.
 */
class NavLabelController extends Controller
{
    public const KEY = 'ui.nav_labels';

    public function show()
    {
        return response()->json(['labels' => (object) self::stored()]);
    }

    public function update(Request $request, AuditLogger $audit)
    {
        abort_unless(RoleHierarchy::isSuper($request->user()), 403, 'Only an Administrator can rename menus.');
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);
        $label = trim((string) ($data['label'] ?? ''));
        $before = null;
        $labels = IntegrationSetting::mutateJson(self::KEY, function (array $labels) use ($data, $label, &$before) {
            $labels = array_filter($labels, 'is_string');
            $before = $labels[$data['key']] ?? null;
            if ($label === '') {
                unset($labels[$data['key']]); // back to the built-in name
            } else {
                $labels[$data['key']] = $label;
            }

            return $labels;
        });
        if ($before !== ($labels[$data['key']] ?? null)) {
            $audit->record('updated', 'nav_label', [$data['key'] => ['old' => $before, 'new' => $labels[$data['key']] ?? null]], 'Sidebar menu name');
        }

        return response()->json(['labels' => (object) $labels]);
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        return is_array($saved) ? array_filter($saved, 'is_string') : [];
    }
}
