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
        Schema::table('markup_rules', function (Blueprint $table) {
            // SIMPLE keeps the existing value/unit (PERCENTAGE/BAHT on top of the line's own
            // amount) behavior. FORMULA computes the result directly from other charge codes in
            // the same quote (see ChargeFormulaEvaluator) — same Excel-style syntax as
            // ChargeFixedOverride's formula, e.g. VAT = ({BASE} + {FF}) * 7%.
            $table->enum('rule_type', ['SIMPLE', 'FORMULA'])->default('SIMPLE')->after('charge_code_id');
            $table->string('formula', 500)->nullable()->after('rule_type');
        });

        // No doctrine/dbal installed — widen value/unit to nullable via raw SQL instead of
        // Schema::table(...)->change() (FORMULA rules don't use them).
        DB::statement("ALTER TABLE markup_rules MODIFY value DECIMAL(10,2) NULL");
        DB::statement("ALTER TABLE markup_rules MODIFY unit ENUM('PERCENTAGE','BAHT') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE markup_rules SET value = 0 WHERE value IS NULL");
        DB::statement("UPDATE markup_rules SET unit = 'PERCENTAGE' WHERE unit IS NULL");
        DB::statement("ALTER TABLE markup_rules MODIFY value DECIMAL(10,2) NOT NULL");
        DB::statement("ALTER TABLE markup_rules MODIFY unit ENUM('PERCENTAGE','BAHT') NOT NULL");

        Schema::table('markup_rules', function (Blueprint $table) {
            $table->dropColumn(['rule_type', 'formula']);
        });
    }
};
