<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_lines', function (Blueprint $table) {
            // "ใบแจ้งหนี้เลขที่ / INVOICE No." column on the Tax Invoice template — an optional
            // external reference number, only ever printed on the TAX_INVOICE page (never used
            // by CASH_RECEIPT's single-amount-column layout).
            $table->string('invoice_no')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_lines', function (Blueprint $table) {
            $table->dropColumn('invoice_no');
        });
    }
};
