<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which package an Insurance item is for — a Box or a Document package (/config/addon → Insurance).
     * Every existing item starts as Box (the default); a Document one is switched over by hand.
     */
    public function up(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            $table->string('package_type', 10)->default('box')->after('product_types');
        });
    }

    public function down(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            $table->dropColumn('package_type');
        });
    }
};
