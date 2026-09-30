<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen provider from a fixed UPS/DHL enum to a free-form string so new carriers
     * (e.g. SAGAWA) can be added without a schema migration.
     */
    public function up(): void
    {
        // sqlite (test suite) has no MODIFY — Laravel's native change() rebuilds the column there.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('charge_codes', fn (Blueprint $table) => $table->string('provider', 10)->change());

            return;
        }
        DB::statement("ALTER TABLE charge_codes MODIFY provider VARCHAR(10) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE charge_codes MODIFY provider ENUM('UPS','DHL') NOT NULL");
    }
};
