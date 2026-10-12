<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Weight Markup got its own permissions (view / edit). Its page first shipped under Mark-up, so
 * every Role that has Mark-up keeps it — nobody gains or loses anything until an admin changes it.
 */
return new class extends Migration
{
    private const GRANTS = ['config.weight_markups', 'config.weight_markups_edit'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('config.markup', $permissions, true) && array_diff(self::GRANTS, $permissions)) {
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
