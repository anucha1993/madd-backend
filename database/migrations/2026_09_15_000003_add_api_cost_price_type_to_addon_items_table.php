<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * API_COST — sell price equals the carrier's own real API-quoted charge as-is (e.g. DHL's
     * actual Declared Value/Insurance line), instead of a configured FIXED/PERCENT/MANUAL price.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE addon_items MODIFY price_type ENUM('FIXED', 'MANUAL', 'PERCENT', 'API_COST') NOT NULL DEFAULT 'MANUAL'");
    }

    public function down(): void
    {
        DB::statement("UPDATE addon_items SET price_type = 'MANUAL' WHERE price_type = 'API_COST'");
        DB::statement("ALTER TABLE addon_items MODIFY price_type ENUM('FIXED', 'MANUAL', 'PERCENT') NOT NULL DEFAULT 'MANUAL'");
    }
};
