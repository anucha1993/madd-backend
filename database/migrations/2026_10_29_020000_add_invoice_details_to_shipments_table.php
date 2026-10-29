<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial Invoice details chosen on /shipment/create, kept so a shipment can be cloned and
 * audited: purpose of shipment (UPS ReasonForExport / DHL exportReasonType), the invoice's
 * currency (customs values — insurance stays THB), and every supporting file uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('export_reason', 20)->nullable()->after('invoice_lines');
            $table->string('invoice_currency', 3)->nullable()->after('export_reason');
            $table->json('commercial_invoice_upload_keys')->nullable()->after('invoice_currency');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['export_reason', 'invoice_currency', 'commercial_invoice_upload_keys']);
        });
    }
};
