<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The carrier's own quoted total (UPS NegotiatedRateCharges/TotalCharges, or DHL's
     * totalPrice) captured BEFORE ChargeMarkupService applies any fixed override/markup — this
     * is the only trustworthy "real cost" reference, kept separate from freight_amount/
     * order_total (which are the customer-facing sell price, markup already baked in).
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('cost_amount', 12, 2)->nullable()->after('order_total');
            $table->string('cost_currency', 3)->nullable()->after('cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['cost_amount', 'cost_currency']);
        });
    }
};
