<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom column header names per profile — keyed by column id, or by group id for combined
// columns (whose joined labels get too long). Additive only: existing profiles keep null = labels.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('column_profiles', function (Blueprint $table) {
            $table->json('headers')->nullable()->after('group_of');
        });
    }

    public function down(): void
    {
        Schema::table('column_profiles', function (Blueprint $table) {
            $table->dropColumn('headers');
        });
    }
};
