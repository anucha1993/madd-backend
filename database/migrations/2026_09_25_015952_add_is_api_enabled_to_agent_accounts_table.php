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
        Schema::table('agent_accounts', function (Blueprint $table) {
            // False = a carrier with NO real API integration (e.g. Kerry, Flash — other
            // couriers never booked through this system's UPS/DHL flow, see 2026-09-25) —
            // excluded from rate-checking/booking on /shipment/create, but still selectable as a
            // carrier label when issuing a Receipt/Tax Invoice for a manually-entered shipment.
            $table->boolean('is_api_enabled')->default(true)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->dropColumn('is_api_enabled');
        });
    }
};
