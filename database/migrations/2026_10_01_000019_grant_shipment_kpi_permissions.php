<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The summary cards on the Shipments page (GET /shipments/stats) get their own permissions
 * (`shipment_kpi.*`). Every Role that can see shipments keeps every card it saw before, so
 * nothing changes until an admin narrows it in Roles & Permissions.
 */
return new class extends Migration
{
    private const KPIS = ['shipment_kpi.today', 'shipment_kpi.in_transit', 'shipment_kpi.month', 'shipment_kpi.revenue', 'shipment_kpi.cancelled'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('shipment.view', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...self::KPIS])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], self::KPIS));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
