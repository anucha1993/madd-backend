<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who voided a shipment and why, plus — for carriers with no cancel API (DHL Express) — whether
 * the carrier has actually been told and confirmed it:
 * carrier_cancel_status 'pending' (voided in MADD, DHL still to be contacted) -> 'confirmed'
 * (DHL confirmed, with their reference). Null for UPS, which is cancelled via its own API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('voided_by')->nullable()->after('void_note')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
            $table->string('carrier_cancel_status', 20)->nullable()->after('void_reason');
            $table->timestamp('carrier_cancel_confirmed_at')->nullable()->after('carrier_cancel_status');
            $table->foreignId('carrier_cancel_confirmed_by')->nullable()->after('carrier_cancel_confirmed_at')->constrained('users')->nullOnDelete();
            $table->string('carrier_cancel_reference')->nullable()->after('carrier_cancel_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_cancel_confirmed_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['void_reason', 'carrier_cancel_status', 'carrier_cancel_confirmed_at', 'carrier_cancel_reference']);
        });
    }
};
