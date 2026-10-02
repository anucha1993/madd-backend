<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shipments: one permission per button, matching the action menu. A Role gets each new one
 * only if it held the permission that used to show that button; `shipment.carrier_cancel` is
 * replaced by its three buttons.
 */
return new class extends Migration
{
    /** new permission => every permission it used to need (all of them). */
    private const SPLITS = [
        'shipment.detail' => ['shipment.view'],
        'shipment.label_all' => ['shipment.label'],
        'shipment.waybill_original' => ['shipment.waybill'],
        'shipment.issue_receipt' => ['shipment.view', 'receipt.create'],
        'shipment.mark_picked_up' => ['pickup.confirm'],
        'shipment.cancel_copy' => ['shipment.carrier_cancel'],
        'shipment.cancel_notified' => ['shipment.carrier_cancel'],
        'shipment.cancel_confirmed' => ['shipment.carrier_cancel'],
    ];

    private const REPLACED = ['shipment.carrier_cancel'];

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
            $next = array_values(array_diff(array_unique([...$permissions, ...$add]), self::REPLACED));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($next)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (array_intersect(['shipment.cancel_copy', 'shipment.cancel_notified', 'shipment.cancel_confirmed'], $permissions)) {
                $permissions[] = 'shipment.carrier_cancel';
            }
            $permissions = array_values(array_unique(array_diff($permissions, array_keys(self::SPLITS))));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
