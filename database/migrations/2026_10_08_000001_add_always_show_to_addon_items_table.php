<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            // Insurance only: a special-rate item (e.g. "ICDV,UPSC Ver.03") listed as an EXTRA
            // choice next to the usual carrier-own/UPSC pair instead of competing for one of
            // those two slots — still filtered by carriers/customer_types/product_types, never
            // auto-selected.
            $table->boolean('always_show')->default(false)->after('trigger_type');
        });
    }

    public function down(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            $table->dropColumn('always_show');
        });
    }
};
