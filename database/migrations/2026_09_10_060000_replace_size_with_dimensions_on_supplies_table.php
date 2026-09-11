<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn('size');
            $table->decimal('weight', 8, 2)->nullable()->after('type');
            $table->decimal('length', 8, 2)->nullable()->after('weight');
            $table->decimal('width', 8, 2)->nullable()->after('length');
            $table->decimal('height', 8, 2)->nullable()->after('width');
            $table->string('icon_url')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn(['weight', 'length', 'width', 'height', 'icon_url']);
            $table->string('size')->nullable()->after('type');
        });
    }
};
