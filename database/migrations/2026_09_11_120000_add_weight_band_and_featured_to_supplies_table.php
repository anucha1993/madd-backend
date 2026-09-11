<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->foreignId('weight_band_id')->nullable()->after('height')->constrained('product_weight_bands')->nullOnDelete();
            $table->boolean('is_featured')->default(false)->after('weight_band_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weight_band_id');
            $table->dropColumn('is_featured');
        });
    }
};
