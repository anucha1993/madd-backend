<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Snapshot of the selected shipments' order_total sum at issue time — kept even
            // though the line-items total is no longer required to match it exactly (2026-09-24:
            // some customers are billed MORE than the shipment sell price). Diffing this against
            // grand_total (via the variance_amount accessor) is how the over/under-charge amount
            // is recorded and surfaced in Reports/UI. Null for documents issued before this change.
            $table->decimal('shipment_total_snapshot', 12, 2)->nullable()->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('shipment_total_snapshot');
        });
    }
};
