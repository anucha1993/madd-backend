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
            // true only for codes created via the "+ Add Charge Code" form on /config/markup —
            // i.e. codes the carrier API never actually returns (e.g. a self-defined "VAT" line).
            $table->boolean('is_custom')->default(false)->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            $table->dropColumn('is_custom');
        });
    }
};
