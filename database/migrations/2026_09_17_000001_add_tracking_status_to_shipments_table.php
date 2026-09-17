<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Separate from `status` (booking lifecycle: pending/booked/failed/voided) —
            // this tracks the carrier's REAL delivery progress, synced periodically via
            // SyncShipmentTracking. Only meaningful while status='booked'.
            $table->string('tracking_status')->nullable()->after('status');
            $table->string('tracking_raw_status')->nullable()->after('tracking_status');
            $table->timestamp('tracking_synced_at')->nullable()->after('tracking_raw_status');
            $table->timestamp('delivered_at')->nullable()->after('tracking_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['tracking_status', 'tracking_raw_status', 'tracking_synced_at', 'delivered_at']);
        });
    }
};
