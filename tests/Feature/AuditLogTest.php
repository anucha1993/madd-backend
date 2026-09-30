<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        DB::table('role_user')->insert(['role_id' => Role::where('key', 'admin')->value('id'), 'user_id' => $admin->id]);

        return $admin;
    }

    public function test_role_changes_are_logged_with_before_and_after(): void
    {
        Sanctum::actingAs($this->admin());
        $staff = Role::where('key', 'staff')->first();

        $this->putJson("/api/roles/{$staff->id}", ['permissions' => ['shipment.view']])->assertOk();

        $log = AuditLog::where('subject_type', 'Role')->where('event', 'updated')->latest('id')->first();
        $this->assertSame('Staff', $log->subject_label);
        $this->assertContains('shipment.create', $log->changes['permissions']['old']);
        $this->assertSame(['shipment.view'], $log->changes['permissions']['new']);
    }

    public function test_user_role_assignment_and_password_are_logged_masked(): void
    {
        Sanctum::actingAs($this->admin());
        $staffRoleId = Role::where('key', 'staff')->value('id');

        $user = $this->postJson('/api/users', [
            'name' => 'New', 'username' => 'new1', 'email' => 'n@x.test', 'password' => 'secret123', 'role_ids' => [$staffRoleId],
        ])->assertCreated()->json();

        $created = AuditLog::where('subject_type', 'User')->where('subject_id', $user['id'])->where('event', 'created')->first();
        $this->assertSame('***', $created->changes['password']['new']);
        $roles = AuditLog::where('event', 'roles_changed')->where('subject_id', $user['id'])->first();
        $this->assertSame(['old' => [], 'new' => ['Staff']], $roles->changes['roles']);
    }

    public function test_system_actions_without_a_user_are_not_logged_and_viewing_needs_permission(): void
    {
        Role::where('key', 'staff')->first()->update(['name' => 'Staff X']); // no auth user
        $this->assertSame(0, AuditLog::count());

        $staff = User::factory()->create();
        $staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        Sanctum::actingAs($staff);
        $this->getJson('/api/audit-logs')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/audit-logs?subject_type=User')->assertOk()->assertJsonStructure(['data', 'subject_types', 'total']);
    }
}
