<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // New `status` value: 'voided'. UPS actually cancels the air waybill with UPS via
            // the Void Shipment API; DHL has NO shipment-cancel API at all (confirmed against
            // DHL's own OpenAPI spec — only Pickup has a DELETE endpoint) so DHL "void" is only
            // ever a local status marker, never a real carrier-side cancellation.
            $table->timestamp('voided_at')->nullable()->after('error_message');
            $table->text('void_note')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_note']);
        });
    }
};
