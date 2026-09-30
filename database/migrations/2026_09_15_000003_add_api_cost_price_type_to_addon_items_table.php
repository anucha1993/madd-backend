<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API_COST — sell price equals the carrier's own real API-quoted charge as-is (e.g. DHL's
     * actual Declared Value/Insurance line), instead of a configured FIXED/PERCENT/MANUAL price.
     */
    public function up(): void
    {
        // sqlite (test suite) has no MODIFY — Laravel's native change() rebuilds the column there.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('addon_items', fn (Blueprint $table) => $table->enum('price_type', ['FIXED', 'MANUAL', 'PERCENT', 'API_COST'])->default('MANUAL')->change());

            return;
        }
        DB::statement("ALTER TABLE addon_items MODIFY price_type ENUM('FIXED', 'MANUAL', 'PERCENT', 'API_COST') NOT NULL DEFAULT 'MANUAL'");
    }

    public function down(): void
    {
        DB::statement("UPDATE addon_items SET price_type = 'MANUAL' WHERE price_type = 'API_COST'");
        DB::statement("ALTER TABLE addon_items MODIFY price_type ENUM('FIXED', 'MANUAL', 'PERCENT') NOT NULL DEFAULT 'MANUAL'");
    }
};
