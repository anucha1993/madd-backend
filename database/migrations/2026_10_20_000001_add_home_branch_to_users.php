<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The branch a user belongs to — every Shipment they book is booked under it. Separate from the
// branches they may SEE (branch_user / can_access_all_branches). Additive: a user with exactly one
// branch gets it as their own; anyone else keeps picking at booking until one is set.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('can_access_all_branches')->constrained('branches')->nullOnDelete();
        });

        $single = DB::table('branch_user')->select('user_id', DB::raw('MIN(branch_id) as branch_id'))
            ->groupBy('user_id')->havingRaw('COUNT(*) = 1')->get();
        foreach ($single as $row) {
            DB::table('users')->where('id', $row->user_id)->whereNull('branch_id')->update(['branch_id' => $row->branch_id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
