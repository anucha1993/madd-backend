<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_carrier_accounts', function (Blueprint $table) {
            // Which service/product codes this branch may quote with this account
            // (UPS: 65/07/08/11, DHL: product codes e.g. P/U/Y) — null/empty = no restriction (all allowed).
            $table->json('allowed_service_codes')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('branch_carrier_accounts', function (Blueprint $table) {
            $table->dropColumn('allowed_service_codes');
        });
    }
};
