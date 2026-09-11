<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_codes', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', ['UPS', 'DHL']);
            $table->string('code', 50);
            $table->string('label', 100);
            $table->string('description', 255)->nullable();
            $table->string('category', 50)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_codes');
    }
};
