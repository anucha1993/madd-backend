<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Free-text tracking/shipment numbers for shipments booked with OTHER carriers that
            // are never tracked as a real Shipment row in this system (2026-09-25) — lets staff
            // issue a Cash Receipt/Tax Invoice for them anyway. Unlike real shipment_ids (locked
            // via receipt_shipment's unique index), these are never locked/deduplicated — the
            // same external tracking number can appear on multiple receipts.
            $table->json('manual_shipment_refs')->nullable()->after('shipment_total_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('manual_shipment_refs');
        });
    }
};
