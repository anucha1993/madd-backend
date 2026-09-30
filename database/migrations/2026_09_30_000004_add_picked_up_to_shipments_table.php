<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An on-call Pickup is never tied to tracking numbers on the carrier side, so "the courier
 * actually collected this shipment" can only come from each shipment's own tracking scan
 * (SyncShipmentTracking / TrackingStatusClassifier) — or from staff confirming it by hand
 * before the scan arrives. picked_up_source records which one set it ('carrier' always wins).
 *
 * Also grants the new `pickup.confirm` permission to the seeded roles that can already
 * schedule pickups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('picked_up_at')->nullable()->after('tracking_synced_at');
            $table->string('picked_up_source', 20)->nullable()->after('picked_up_at');
            $table->foreignId('picked_up_by')->nullable()->after('picked_up_source')->constrained('users')->nullOnDelete();
        });

        foreach (DB::table('roles')->whereIn('key', ['staff', 'branch_manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('pickup.create', $permissions, true) && ! in_array('pickup.confirm', $permissions, true)) {
                $permissions[] = 'pickup.confirm';
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picked_up_by');
            $table->dropColumn(['picked_up_at', 'picked_up_source']);
        });
    }
};
