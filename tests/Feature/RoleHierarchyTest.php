<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $key, int $level, array $permissions = []): Role
    {
        return Role::create(['key' => $key, 'name' => ucfirst($key), 'level' => $level, 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
    }

    private function userWith(Role $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_only_roles_and_users_ranked_below_can_be_managed(): void
    {
        $perms = ['user.roles', 'user.roles_delete', 'user.view', 'user.create', 'user.edit', 'user.delete', 'shipment.view'];
        $manager = $this->role('manager', 50, $perms);
        $peer = $this->role('peer', 50);
        $senior = $this->role('senior', 70);
        $junior = $this->role('junior', 30, ['shipment.view']);
        $me = $this->userWith($manager);
        Sanctum::actingAs($me);

        $roles = collect($this->getJson('/api/roles')->assertOk()->json())->keyBy('key');
        $this->assertTrue($roles['junior']['manageable']);
        $this->assertFalse($roles['peer']['manageable']);      // equal can't touch equal
        $this->assertFalse($roles['senior']['manageable']);
        $this->assertFalse($roles['manager']['manageable']);   // not even my own

        $this->putJson("/api/roles/{$junior->id}", ['name' => 'Junior 2'])->assertOk();
        $this->putJson("/api/roles/{$peer->id}", ['name' => 'x'])->assertForbidden();
        $this->putJson("/api/roles/{$senior->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/roles/{$peer->id}")->assertForbidden();
        $this->putJson("/api/roles/{$junior->id}", ['level' => 50])->assertUnprocessable();   // can't lift it to my level
        $this->postJson('/api/roles', ['key' => 'twin', 'name' => 'Twin', 'level' => 50])->assertUnprocessable();
        $this->postJson('/api/roles', ['key' => 'low', 'name' => 'Low', 'level' => 20])->assertCreated();

        // Grants are capped at what I hold myself.
        $this->putJson("/api/roles/{$junior->id}", ['permissions' => ['shipment.view', 'config.markup']])->assertOk()
            ->assertJsonPath('permissions', ['shipment.view']);

        // Users: below me only, and only Roles below me can be handed out.
        $peerUser = $this->userWith($peer);
        $juniorUser = $this->userWith($junior);
        $this->putJson("/api/users/{$juniorUser->id}", ['name' => 'J'])->assertOk();
        $this->putJson("/api/users/{$peerUser->id}", ['name' => 'P'])->assertForbidden();
        $this->deleteJson("/api/users/{$peerUser->id}")->assertForbidden();
        $this->putJson("/api/users/{$juniorUser->id}", ['role_ids' => [$peer->id]])->assertUnprocessable();
        $this->putJson("/api/users/{$me->id}", ['name' => 'me'])->assertForbidden();
    }

    public function test_super_admin_manages_everything_without_any_permission_set(): void
    {
        $super = Role::create(['key' => 'boss', 'name' => 'Boss', 'is_super_admin' => true, 'level' => 1000, 'permissions' => [], 'field_access' => [], 'data_scopes' => []]);
        $senior = $this->role('senior', 900);
        Sanctum::actingAs($this->userWith($super));

        $this->putJson("/api/roles/{$senior->id}", ['name' => 'Senior 2'])->assertOk();
        $this->assertTrue(collect($this->getJson('/api/roles')->json())->every(fn ($r) => $r['manageable']));
        $this->getJson('/api/me')->assertOk();
    }
}
