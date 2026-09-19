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
            // Pinned charge codes surface as quick-select chips on /config/markup and Fixed
            // Charges instead of staff having to search every time for the handful they use daily.
            $table->boolean('is_pinned')->default(false)->after('is_custom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });
    }
};
