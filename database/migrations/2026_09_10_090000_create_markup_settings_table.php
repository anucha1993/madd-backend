<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->decimal('markup_percent', 5, 2)->default(0);
            $table->decimal('fuel_percent', 5, 2)->default(0);
            $table->decimal('vat_percent', 5, 2)->default(0);
            $table->decimal('form_fee', 10, 2)->default(0);
            $table->decimal('ot_fee', 10, 2)->default(0);
            $table->decimal('metal_box_fee', 10, 2)->default(0);
            $table->decimal('other_fee', 10, 2)->default(0);
            $table->timestamps();

            $table->unique('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markup_settings');
    }
};
