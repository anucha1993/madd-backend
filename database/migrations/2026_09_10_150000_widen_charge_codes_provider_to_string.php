<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen provider from a fixed UPS/DHL enum to a free-form string so new carriers
     * (e.g. SAGAWA) can be added without a schema migration.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE charge_codes MODIFY provider VARCHAR(10) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE charge_codes MODIFY provider ENUM('UPS','DHL') NOT NULL");
    }
};
