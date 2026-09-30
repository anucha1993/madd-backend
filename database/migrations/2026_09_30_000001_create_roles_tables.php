<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable Roles (see config/permissions.php for what can be granted). A Role's selections
 * are kept as JSON on the row itself rather than normalized pivot tables — the registry lives in
 * config, so there's nothing to join against, and the Roles UI saves one Role as one document.
 *
 * users.role (admin/staff) is left in place untouched as a historical column — access is now
 * resolved purely from role_user. Existing users are mapped admin -> Admin, staff -> Staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // Bypasses every check (all actions, every field at 'edit', scope 'all').
            $table->boolean('is_super_admin')->default(false);
            // Seeded by this migration (their `key` stays fixed; see RoleController).
            $table->boolean('is_system')->default(false);
            $table->json('permissions');
            $table->json('field_access');
            $table->json('data_scopes');
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        $now = now();
        $roles = [
            [
                'key' => 'admin',
                'name' => 'Admin',
                'description' => 'เข้าถึงได้ทุกเมนู ทุกฟิลด์ ทุกสาขา',
                'is_super_admin' => true,
                'permissions' => [],
                'field_access' => [],
                'data_scopes' => [],
            ],
            [
                'key' => 'branch_manager',
                'name' => 'Branch Manager',
                'description' => 'ดูแลงานทั้งหมดของสาขาตัวเอง รวมถึงต้นทุนและรายงาน',
                'permissions' => [
                    'shipment.view', 'shipment.create', 'shipment.void', 'shipment.delete',
                    'pickup.view', 'pickup.create', 'pickup.cancel',
                    'receipt.view', 'receipt.create', 'receipt.edit', 'receipt.void', 'receipt.delete',
                    'billing_customer.manage', 'customer.manage', 'tracking.view',
                    'report.manifest', 'report.summary', 'report.finance',
                ],
                'field_access' => ['shipment' => ['cost' => 'view', 'carrier_raw' => 'view']],
                'data_scopes' => ['shipment' => 'branch', 'pickup' => 'branch', 'receipt' => 'branch'],
            ],
            [
                'key' => 'staff',
                'name' => 'Staff',
                'description' => 'พนักงานหน้าร้าน — จองงาน ออกใบเสร็จ ในสาขาตัวเอง ไม่เห็นต้นทุน',
                'permissions' => [
                    'shipment.view', 'shipment.create',
                    'pickup.view', 'pickup.create',
                    'receipt.view', 'receipt.create',
                    'billing_customer.manage', 'customer.manage', 'tracking.view',
                ],
                'field_access' => ['shipment' => ['cost' => 'hidden', 'carrier_raw' => 'hidden']],
                'data_scopes' => ['shipment' => 'branch', 'pickup' => 'branch', 'receipt' => 'branch'],
            ],
            [
                'key' => 'accounting',
                'name' => 'Accounting',
                'description' => 'บัญชี — ดูทุกสาขา จัดการใบเสร็จ/ใบกำกับภาษีและรายงาน ไม่จอง Shipment',
                'permissions' => [
                    'shipment.view',
                    'receipt.view', 'receipt.create', 'receipt.edit', 'receipt.void',
                    'billing_customer.manage', 'tracking.view',
                    'report.manifest', 'report.summary', 'report.finance',
                ],
                'field_access' => ['shipment' => ['cost' => 'view', 'billing' => 'view', 'references' => 'view', 'carrier_raw' => 'hidden']],
                'data_scopes' => ['shipment' => 'all', 'pickup' => 'all', 'receipt' => 'all'],
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->insert([
                'key' => $role['key'],
                'name' => $role['name'],
                'description' => $role['description'],
                'is_super_admin' => $role['is_super_admin'] ?? false,
                'is_system' => true,
                'permissions' => json_encode($role['permissions']),
                'field_access' => json_encode($role['field_access'], JSON_FORCE_OBJECT),
                'data_scopes' => json_encode($role['data_scopes'], JSON_FORCE_OBJECT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleIds = DB::table('roles')->pluck('id', 'key');
        foreach (DB::table('users')->get(['id', 'role']) as $user) {
            DB::table('role_user')->insert([
                'role_id' => $roleIds[$user->role === 'admin' ? 'admin' : 'staff'],
                'user_id' => $user->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
