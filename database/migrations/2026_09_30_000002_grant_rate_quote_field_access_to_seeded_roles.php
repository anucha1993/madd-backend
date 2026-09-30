<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * config/permissions.php gained the `rate` field groups (carrier cost / markup detail / raw
 * inside rate quotes), all defaulting to hidden. Keeps the seeded manager/accounting roles able
 * to see what they already could via shipment cost; Staff stays on the hidden defaults. Only
 * fills a role whose `rate` access was never set, so edits made in the Roles UI are kept.
 */
return new class extends Migration
{
    private const GRANTS = [
        'branch_manager' => ['cost' => 'view', 'markup' => 'view', 'carrier_raw' => 'view'],
        'accounting' => ['cost' => 'view', 'markup' => 'view', 'carrier_raw' => 'hidden'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $key => $levels) {
            $role = DB::table('roles')->where('key', $key)->first();
            if (! $role) {
                continue;
            }
            $fieldAccess = json_decode($role->field_access, true) ?: [];
            if (isset($fieldAccess['rate'])) {
                continue;
            }
            $fieldAccess['rate'] = $levels;
            DB::table('roles')->where('id', $role->id)->update(['field_access' => json_encode($fieldAccess)]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $key) {
            $role = DB::table('roles')->where('key', $key)->first();
            if (! $role) {
                continue;
            }
            $fieldAccess = json_decode($role->field_access, true) ?: [];
            unset($fieldAccess['rate']);
            DB::table('roles')->where('id', $role->id)->update(['field_access' => json_encode($fieldAccess, JSON_FORCE_OBJECT)]);
        }
    }
};
