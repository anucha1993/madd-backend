<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rate card's new Cost button (rate.profit — carrier cost vs sell price, profit) is shown to
 * every Role that can already see the carrier cost: the profit is just sell − that cost. Everyone
 * else stays hidden until an admin turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $access = json_decode((string) $role->field_access, true) ?: [];
            if (($access['rate']['cost'] ?? null) === 'view' && ! isset($access['rate']['profit'])) {
                $access['rate']['profit'] = 'view';
                DB::table('roles')->where('id', $role->id)->update(['field_access' => json_encode($access)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $access = json_decode((string) $role->field_access, true) ?: [];
            if (isset($access['rate']['profit'])) {
                unset($access['rate']['profit']);
                DB::table('roles')->where('id', $role->id)->update(['field_access' => json_encode($access)]);
            }
        }
    }
};
