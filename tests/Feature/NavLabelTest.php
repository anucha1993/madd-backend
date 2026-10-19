<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NavLabelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(array $attrs): void
    {
        $role = Role::create($attrs + ['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => [], 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_only_super_admin_renames_and_everyone_reads(): void
    {
        $this->actingAsRole(['is_super_admin' => true]);
        $this->putJson('/api/nav-labels', ['key' => '/pickup/list', 'label' => 'รายการรับพัสดุ'])->assertOk()
            ->assertJsonPath('labels./pickup/list', 'รายการรับพัสดุ');
        $this->putJson('/api/nav-labels', ['key' => 'section:Billing', 'label' => 'บัญชี'])->assertOk();
        $this->putJson('/api/nav-labels', ['key' => 'section:Billing', 'label' => ''])->assertOk()
            ->assertJsonMissingPath('labels.section:Billing'); // cleared → built-in name

        // Even a user holding every permission isn't an Administrator.
        $this->actingAsRole(['permissions' => array_keys(config('permissions.actions', [])), 'level' => 999]);
        $this->getJson('/api/nav-labels')->assertOk()->assertJsonPath('labels./pickup/list', 'รายการรับพัสดุ');
        $this->putJson('/api/nav-labels', ['key' => '/pickup/list', 'label' => 'x'])->assertForbidden();
    }
}
