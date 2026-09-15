<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-account fixed charge overrides — intercepts the carrier's raw chargeBreakdown
     * amount for a given charge code BEFORE the (separate) MarkupRule value/unit markup
     * is applied on top, so a charge like Fuel Surcharge can be pinned to a fixed price
     * regardless of what the live API quotes.
     */
    public function up(): void
    {
        Schema::create('charge_fixed_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_code_id')->constrained()->cascadeOnDelete();
            $table->decimal('fixed_amount', 10, 2);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['agent_account_id', 'charge_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_fixed_overrides');
    }
};
