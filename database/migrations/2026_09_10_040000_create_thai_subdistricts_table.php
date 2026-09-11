<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thai_subdistricts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tambon_id')->unique();
            $table->string('name_th');
            $table->string('name_en')->nullable();
            $table->unsignedInteger('district_id');
            $table->string('district_name_th');
            $table->string('district_name_en')->nullable();
            $table->unsignedInteger('province_id');
            $table->string('province_name_th');
            $table->string('province_name_en')->nullable();
            $table->string('region')->nullable();
            $table->string('zip_code', 10);
            $table->string('zip_code_all', 50)->nullable();
            $table->timestamps();

            $table->index('zip_code');
            $table->index('province_name_th');
            $table->index('district_name_th');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thai_subdistricts');
    }
};
