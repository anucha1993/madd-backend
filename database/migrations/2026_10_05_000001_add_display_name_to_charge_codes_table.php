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
        Schema::table('charge_codes', function (Blueprint $table) {
            // Staff's own name for a charge (e.g. BASE → "ค่าขนส่ง") shown everywhere a charge
            // line is displayed and suggested on Receipts / Tax Invoices. The carrier's own
            // description in rate_quote.chargeBreakdown is never overwritten — reports still
            // keyword-match on it (see KeyBillingReportService).
            $table->string('display_name', 150)->nullable()->after('label');
            // Optional conditional name: {"code":"190","op":">","value":0,"name":"..."} — when that
            // code's amount on the SAME quote passes the test, show "name" instead of display_name
            // (a code missing from the quote counts as 0). See ChargeCode::displayNameFor().
            $table->json('display_rule')->nullable()->after('display_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'display_rule']);
        });
    }
};
