<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer Display (2nd screen): the "open" button goes to every Role that can already create
 * shipments; its settings page to every Role that can already edit Mark-up.
 */
return new class extends Migration
{
    private const GRANTS = [
        'shipment.create' => ['shipment.customer_display'],
        'config.markup' => ['config.customer_display', 'config.customer_display_edit'],
    ];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            $grants = [];
            foreach (self::GRANTS as $has => $gets) {
                if (in_array($has, $permissions, true)) {
                    $grants = [...$grants, ...$gets];
                }
            }
            if (array_diff($grants, $permissions)) {
                DB::table('roles')->where('id', $role->id)
                    ->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$grants])))]);
            }
        }
    }

    public function down(): void
    {
        $all = array_merge(...array_values(self::GRANTS));
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            DB::table('roles')->where('id', $role->id)
                ->update(['permissions' => json_encode(array_values(array_diff($permissions, $all)))]);
        }
    }
};
