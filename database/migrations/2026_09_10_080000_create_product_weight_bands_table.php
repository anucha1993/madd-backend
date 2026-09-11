<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_weight_bands', function (Blueprint $table) {
            $table->id();
            // e.g. CPM10, CPM25, REG 5.1-20KG, DOCUMENT, F/C
            $table->string('code');
            $table->string('label');
            $table->enum('package_type', ['box', 'document'])->default('box');
            $table->decimal('min_weight', 8, 2)->nullable();
            $table->decimal('max_weight', 8, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_weight_bands');
    }
};
