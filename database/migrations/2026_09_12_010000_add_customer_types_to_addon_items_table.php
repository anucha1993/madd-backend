<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            // Null/empty = applies to every Customer Type. Otherwise a list of Manifest Options
            // customer_type codes (e.g. DAILY, WI, CR, SCR) this item is restricted to — used so
            // PERCENT items like Insurance can have a different rate per Customer Type.
            $table->json('customer_types')->nullable()->after('carriers');
        });
    }

    public function down(): void
    {
        Schema::table('addon_items', function (Blueprint $table) {
            $table->dropColumn('customer_types');
        });
    }
};
