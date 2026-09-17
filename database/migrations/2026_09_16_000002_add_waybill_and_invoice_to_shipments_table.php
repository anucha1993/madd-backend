<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // UPS returns a Shipper's Copy / Waybill receipt (ShipmentResults.ControlLogReceipt)
            // alongside the per-box label — kept as evidence the shipment was really booked
            // (dispute/insurance reference), separate from the label(s) staff stick on the boxes.
            // DHL has no equivalent document, so this stays null for DHL shipments.
            $table->string('waybill_storage_key')->nullable()->after('label_storage_key');
            // Commercial Invoice for customs (UPS ShipmentResults.Form / DHL documents[typeCode=invoice]),
            // only present when the carrier actually returned one for this shipment.
            $table->string('commercial_invoice_storage_key')->nullable()->after('waybill_storage_key');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['waybill_storage_key', 'commercial_invoice_storage_key']);
        });
    }
};
