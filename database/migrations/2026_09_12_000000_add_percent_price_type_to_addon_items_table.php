<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE addon_items MODIFY price_type ENUM('FIXED', 'MANUAL', 'PERCENT') NOT NULL DEFAULT 'MANUAL'");
    }

    public function down(): void
    {
        DB::statement("UPDATE addon_items SET price_type = 'MANUAL' WHERE price_type = 'PERCENT'");
        DB::statement("ALTER TABLE addon_items MODIFY price_type ENUM('FIXED', 'MANUAL') NOT NULL DEFAULT 'MANUAL'");
    }
};
