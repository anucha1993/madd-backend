<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buttons that used to ride on a broader permission get their own (Roles & Permissions can
 * now switch each one off). A Role gets the new permission only if it already held what used
 * to allow that button, so nobody gains or loses anything until an admin changes it.
 */
return new class extends Migration
{
    /** new permission => every permission it used to need (all of them). */
    private const SPLITS = [
        'shipment.draft' => ['shipment.create'],
        'shipment.assign_branch' => ['shipment.create'],
        'shipment.unvoid' => ['shipment.void'],
        'shipment.carrier_cancel' => ['shipment.void'],
        'receipt.print' => ['receipt.view'],
        'pickup.reschedule' => ['pickup.cancel', 'pickup.create'],
        'supply_stock.adjust' => ['supply_stock.receive'],
        'supply_stock.export' => ['supply_stock.report'],
        'report.manifest_export' => ['report.manifest'],
        'report.summary_export' => ['report.summary'],
        'config.insurance_import' => ['config.insurance'],
        'config.countries_sync' => ['config.countries'],
        'config.agent_accounts_test' => ['config.agent_accounts'],
        'config.tracking_sync_run' => ['config.tracking_sync'],
        'config.report_schedules_send' => ['config.report_schedules'],
        'config.smtp_test' => ['config.smtp'],
        'config.system_alerts_resolve' => ['config.system_alerts'],
        'config.api_clients_test' => ['config.api_clients'],
        'config.api_clients_regenerate' => ['config.api_clients'],
        'config.wordpress_plugin' => ['config.api_clients'],
        'user.roles_delete' => ['user.roles'],
    ];

    /** new permission => any ONE of these used to allow it. */
    private const ANY_OF = [
        'tracking.pod' => ['tracking.view', 'shipment.view'],
        'branch.carrier_accounts' => ['branch.create', 'branch.edit'],
    ];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            $add = [];
            foreach (self::SPLITS as $new => $needs) {
                if (! array_diff($needs, $permissions)) {
                    $add[] = $new;
                }
            }
            foreach (self::ANY_OF as $new => $any) {
                if (array_intersect($any, $permissions)) {
                    $add[] = $new;
                }
            }
            if ($add) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$add])))]);
            }
        }
    }

    public function down(): void
    {
        $all = [...array_keys(self::SPLITS), ...array_keys(self::ANY_OF)];
        foreach (DB::table('roles')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], $all));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
