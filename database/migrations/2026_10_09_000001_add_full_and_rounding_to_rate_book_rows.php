<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two more columns of the business's rate-file template: FULL (the carrier's published,
     * pre-discount freight — DISC % is derived from it) and FREE (the round-up to a whole baht,
     * shown apart from ADD/markup).
     */
    public function up(): void
    {
        Schema::table('rate_book_rows', function (Blueprint $table) {
            $table->decimal('full', 12, 2)->nullable()->after('service_code');
            $table->decimal('rounding', 12, 2)->nullable()->after('markup');
        });
    }

    public function down(): void
    {
        Schema::table('rate_book_rows', function (Blueprint $table) {
            $table->dropColumn(['full', 'rounding']);
        });
    }
};
