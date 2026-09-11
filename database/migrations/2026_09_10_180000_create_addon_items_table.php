<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->json('carriers');
            $table->enum('price_type', ['FIXED', 'MANUAL'])->default('MANUAL');
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('trigger_type', ['MANUAL', 'AUTO'])->default('MANUAL');
            $table->boolean('status')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_items');
    }
};
