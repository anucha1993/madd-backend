<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverts the fixed_amount column added to markup_rules — fixed charge overrides now
     * live in their own charge_fixed_overrides table instead, configured per Agent Account.
     */
    public function up(): void
    {
        // The migration that ADDED fixed_amount was later deleted, so on a fresh database the
        // column never exists — guard so migrations replay from empty (tests, new installs).
        if (! Schema::hasColumn('markup_rules', 'fixed_amount')) {
            return;
        }
        Schema::table('markup_rules', function (Blueprint $table) {
            $table->dropColumn('fixed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('markup_rules', function (Blueprint $table) {
            $table->decimal('fixed_amount', 10, 2)->nullable()->after('charge_code_id');
        });
    }
};
