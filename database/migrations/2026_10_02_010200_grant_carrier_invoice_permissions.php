<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New `carrier_invoice.*` module (OCR reconciliation against UPS/DHL's real invoice) — every
 * Role that can already see Receipts (billing-adjacent) gets view+upload+edit by default so
 * nothing breaks; `delete` is NOT auto-granted (same caution as receipt.delete), narrow later
 * from Roles & Permissions if needed.
 */
return new class extends Migration
{
    private const GRANTED = ['carrier_invoice.view', 'carrier_invoice.upload', 'carrier_invoice.edit'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('receipt.view', $permissions, true)) {
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
