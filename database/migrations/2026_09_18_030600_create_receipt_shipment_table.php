<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_shipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // GLOBAL lock: a shipment may only ever be attached to ONE receipt/tax invoice across
            // BOTH document types, forever — this row is never deleted even if the receipt is
            // later voided (voiding is a status flag only, see receipt-tax-invoice-module note).
            $table->unique('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_shipment');
    }
};
