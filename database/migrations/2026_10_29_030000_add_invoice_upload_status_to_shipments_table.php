<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What really happened to each staff-uploaded invoice file at booking — sent to the carrier or
 * not (with the carrier's reason), and whether it made it into our merged Commercial Invoice PDF.
 * Shown on the shipment so nobody has to trust a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->json('invoice_upload_status')->nullable()->after('commercial_invoice_upload_keys');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('invoice_upload_status');
        });
    }
};
