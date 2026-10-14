<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Heavy Weight sell rates get their own permissions (view / edit) — granted to every Role that can
 * already edit Weight Markup, the closest existing pricing screen. Nobody else gains anything.
 */
return new class extends Migration
{
    private const GRANTS = ['config.heavy_rates', 'config.heavy_rates_edit'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('config.weight_markups_edit', $permissions, true) && array_diff(self::GRANTS, $permissions)) {
                DB::table('roles')->where('id', $role->id)
                    ->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...self::GRANTS])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            DB::table('roles')->where('id', $role->id)
                ->update(['permissions' => json_encode(array_values(array_diff($permissions, self::GRANTS)))]);
        }
    }
};
