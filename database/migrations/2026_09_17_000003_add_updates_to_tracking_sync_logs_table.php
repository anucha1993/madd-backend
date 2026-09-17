<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_sync_logs', function (Blueprint $table) {
            // Per-shipment status changes made THIS run, e.g. [{"shipment_id":5,
            // "tracking_number":"1Z...","from":"in_transit","to":"delivered"}] — lets the log
            // viewer show exactly which shipments changed and how, not just a bare count.
            $table->json('updates')->nullable()->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_sync_logs', function (Blueprint $table) {
            $table->dropColumn('updates');
        });
    }
};
