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
        Schema::table('addon_items', function (Blueprint $table) {
            // Extra % markup applied on top of whatever this item's price_type already computes
            // (FIXED amount, PERCENT-of-declared-value, or the carrier's own API_COST) — e.g. UPSC
            // quotes 1,000 THB and staff still wants to sell it at +2% on top of that.
            $table->decimal('markup_percent', 6, 2)->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
