<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The carrier's own total (COST). The charge columns now hold the sell-side amounts after the
     * account's Fixed Charges (as in the rate-file template), so cost needs its own column.
     */
    public function up(): void
    {
        Schema::table('rate_book_rows', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->after('other');
        });
    }

    public function down(): void
    {
        Schema::table('rate_book_rows', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
