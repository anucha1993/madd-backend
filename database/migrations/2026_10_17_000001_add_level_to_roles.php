<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role hierarchy: a user may only manage Roles / users ranked strictly BELOW their own highest
     * Role (equal can't touch equal). Super Admin stays on top regardless of the number.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(10)->after('is_super_admin');
        });

        DB::table('roles')->where('is_super_admin', true)->update(['level' => 1000]);
        foreach (['branch_manager' => 60, 'accounting' => 50, 'cs' => 40, 'staff' => 30] as $key => $level) {
            DB::table('roles')->where('key', $key)->where('is_super_admin', false)->update(['level' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
