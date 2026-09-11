<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markup_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_code_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 10, 2);
            $table->enum('unit', ['PERCENTAGE', 'BAHT']);
            $table->boolean('status')->default(true);
            $table->timestamps();

            // One markup rule per (account, charge code) — matches the legacy mark_up table's rule.
            $table->unique(['agent_account_id', 'charge_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markup_rules');
    }
};
