<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_country_caps', function (Blueprint $table) {
            $table->id();
            $table->string('country_name');
            $table->string('country_code', 5)->nullable();
            $table->decimal('ups_max_value', 12, 2)->nullable();
            $table->decimal('dhl_max_value', 12, 2)->nullable();
            $table->decimal('ups_max_declared', 12, 2)->nullable();
            $table->decimal('dhl_max_declared', 12, 2)->nullable();
            // e.g. "Sanction" / "War Exclusion" — insurance not available for this country when set.
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_country_caps');
    }
};
