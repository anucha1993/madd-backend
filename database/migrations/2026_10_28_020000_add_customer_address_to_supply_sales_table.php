<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The buyer's address / phone printed on the 80 mm box-sale receipt (picked from /customer or typed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sales', function (Blueprint $table) {
            $table->string('customer_address', 1000)->nullable()->after('customer_name');
            $table->string('customer_phone', 50)->nullable()->after('customer_address');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sales', function (Blueprint $table) {
            $table->dropColumn(['customer_address', 'customer_phone']);
        });
    }
};
