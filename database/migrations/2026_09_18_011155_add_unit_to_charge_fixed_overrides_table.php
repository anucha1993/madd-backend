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
        Schema::table('charge_fixed_overrides', function (Blueprint $table) {
            // Only meaningful when override_type = FIXED — THB is a flat replacement amount
            // (unchanged historical behavior), PERCENTAGE replaces it with that % of the
            // carrier's own original quoted amount for the same charge code (e.g. sell Fuel
            // Surcharge at 90% of whatever the carrier actually quoted).
            $table->enum('unit', ['THB', 'PERCENTAGE'])->default('THB')->after('fixed_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge_fixed_overrides', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
