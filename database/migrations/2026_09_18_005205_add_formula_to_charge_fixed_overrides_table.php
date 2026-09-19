<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('charge_fixed_overrides', function (Blueprint $table) {
            // FORMULA lets staff compute this charge from OTHER charge codes in the same quote
            // (e.g. Fuel Surcharge = (Base Freight + Surge Fee Commercial) * 35%), Excel-style —
            // see ChargeMarkupService::evaluateFormula. FIXED keeps the original plain-number behavior.
            $table->enum('override_type', ['FIXED', 'FORMULA'])->default('FIXED')->after('charge_code_id');
            $table->string('formula', 500)->nullable()->after('override_type');
        });

        // No doctrine/dbal installed, so use raw SQL instead of Schema::table(...)->change() to
        // make fixed_amount optional (FORMULA rows don't use it).
        DB::statement('ALTER TABLE charge_fixed_overrides MODIFY fixed_amount DECIMAL(10,2) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('UPDATE charge_fixed_overrides SET fixed_amount = 0 WHERE fixed_amount IS NULL');
        DB::statement('ALTER TABLE charge_fixed_overrides MODIFY fixed_amount DECIMAL(10,2) NOT NULL');

        Schema::table('charge_fixed_overrides', function (Blueprint $table) {
            $table->dropColumn(['override_type', 'formula']);
        });
    }
};
