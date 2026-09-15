<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            // Restricts an Insurance item to specific box Product Types (SILVER/NON_SILVER/OTHER)
            // set in Create Shipment — null/empty means it applies to every Product Type.
            $table->json('product_types')->nullable()->after('customer_types');
        });
    }

    public function down(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            $table->dropColumn('product_types');
        });
    }
};
