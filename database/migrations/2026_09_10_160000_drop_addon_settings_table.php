<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('addon_settings');
    }

    public function down(): void
    {
        // Intentionally not recreated — Add-on Settings was removed as a concept entirely.
    }
};
