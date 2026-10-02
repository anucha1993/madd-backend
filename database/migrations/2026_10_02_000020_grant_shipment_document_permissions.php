<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opening a shipment's Label / Waybill / Commercial Invoice gets its own permission
 * (`shipment.label|waybill|invoice`) instead of riding on `shipment.view`. Every Role that can
 * see shipments keeps all three, so nothing changes until an admin narrows it in Roles.
 */
return new class extends Migration
{
    private const DOCS = ['shipment.label', 'shipment.waybill', 'shipment.invoice'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('shipment.view', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...self::DOCS])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], self::DOCS));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
