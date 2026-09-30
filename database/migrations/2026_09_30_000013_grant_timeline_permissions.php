<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants the new `shipment.timeline` / `receipt.timeline` / `pickup.timeline` permissions (see
 * TimelineController) to the seeded roles that supervise that work. Staff get none by default —
 * turn it on per Role in Roles & Permissions.
 */
return new class extends Migration
{
    private const GRANTS = [
        'branch_manager' => ['shipment.timeline', 'receipt.timeline', 'pickup.timeline'],
        'accounting' => ['shipment.timeline', 'receipt.timeline'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $key => $grants) {
            $role = DB::table('roles')->where('key', $key)->first();
            if (! $role) {
                continue;
            }
            $permissions = json_decode($role->permissions, true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$permissions, ...$grants]))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = array_values(array_filter(json_decode($role->permissions, true) ?: [], fn ($p) => ! str_ends_with($p, '.timeline')));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
