<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // One shipment can be a multi-piece shipment (several physical boxes booked in a
            // single carrier request) — each physical piece gets its own carrier tracking
            // number (and, for UPS, its own label). `tracking_number` above stays the master/
            // shipment-level number; this holds one {tracking_number, label_storage_key} entry
            // per physical piece, in the same order the carrier returned them.
            $table->json('pieces')->nullable()->after('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('pieces');
        });
    }
};
