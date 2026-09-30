<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The single "manage" permissions are split into view / create / edit / delete (branch also
 * gets doc_numbers). Every Role that held X.manage gets all of X's new actions, so nothing
 * changes for existing users until an admin narrows it in Roles & Permissions.
 *
 * Also adds the new `ai.*` permissions — until now any user who could book shipments could use
 * the AI features, so those Roles keep them.
 */
return new class extends Migration
{
    private const SPLIT = [
        'customer.manage' => ['customer.view', 'customer.create', 'customer.edit', 'customer.delete'],
        'billing_customer.manage' => ['billing_customer.view', 'billing_customer.create', 'billing_customer.edit', 'billing_customer.delete'],
        'branch.manage' => ['branch.view', 'branch.create', 'branch.edit', 'branch.delete', 'branch.doc_numbers'],
        'user.manage' => ['user.view', 'user.create', 'user.edit', 'user.delete'],
    ];

    private const AI = ['ai.rate_chat', 'ai.parse_address'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            $next = [];
            foreach ($permissions as $permission) {
                array_push($next, ...(self::SPLIT[$permission] ?? [$permission]));
            }
            if (in_array('shipment.create', $permissions, true)) {
                array_push($next, ...self::AI);
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($next)))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            foreach (self::SPLIT as $old => $new) {
                if (array_intersect($new, $permissions)) {
                    $permissions = [...array_diff($permissions, $new), $old];
                }
            }
            $permissions = array_diff($permissions, self::AI);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)))]);
        }
    }
};
