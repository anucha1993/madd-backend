<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Weight Markup cell can be a formula instead of THB/kg — e.g. "{BASE}*5%" — evaluated with
     * the same ChargeFormulaEvaluator as Fixed Charges / Mark-up; its result is THB for the whole
     * shipment. rate_per_kg is ignored (0) when a formula is set.
     */
    public function up(): void
    {
        Schema::table('weight_markup_rates', function (Blueprint $table) {
            $table->string('formula', 500)->nullable()->after('rate_per_kg');
        });
    }

    public function down(): void
    {
        Schema::table('weight_markup_rates', function (Blueprint $table) {
            $table->dropColumn('formula');
        });
    }
};
