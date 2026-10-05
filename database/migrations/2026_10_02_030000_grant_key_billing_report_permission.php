<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New `report.key_billing` permission — every Role that can already see the Manifest report
 * (reports-adjacent) gets it by default so nothing breaks; same pattern as
 * 2026_10_02_010200_grant_carrier_invoice_permissions.
 */
return new class extends Migration
{
    private const GRANTED = ['report.key_billing'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('report.manifest', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...self::GRANTED])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], self::GRANTED));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
