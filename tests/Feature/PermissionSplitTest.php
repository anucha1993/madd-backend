<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): User
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_seeded_roles_were_migrated_from_manage(): void
    {
        $staff = Role::where('key', 'staff')->first()->permissions;
        $this->assertNotContains('customer.manage', $staff);
        $this->assertContains('customer.delete', $staff);
        $this->assertContains('ai.rate_chat', $staff);
        $this->assertNotContains('ai.rate_chat', Role::where('key', 'accounting')->first()->permissions);
    }

    public function test_customer_actions_are_checked_separately(): void
    {
        $customer = Customer::create(['name' => 'A']);

        $this->actingWith(['customer.view', 'customer.edit']);
        $this->putJson("/api/customers/{$customer->id}", ['name' => 'B'])->assertOk();
        $this->deleteJson("/api/customers/{$customer->id}")->assertForbidden();
        $this->postJson('/api/customers', ['name' => 'C'])->assertForbidden();

        // Booking staff can still save a new customer from the booking form, but not edit/delete.
        $this->actingWith(['shipment.create']);
        $this->postJson('/api/customers', ['name' => 'C'])->assertCreated();
        $this->putJson("/api/customers/{$customer->id}", ['name' => 'D'])->assertForbidden();
    }

    public function test_ai_features_need_their_own_permission(): void
    {
        $this->actingWith(['shipment.create']);
        $this->postJson('/api/ai/rate-chat', ['message' => 'x'])->assertForbidden();
        $this->postJson('/api/ai/parse-address', ['text' => 'x'])->assertForbidden();
    }

    public function test_users_view_does_not_allow_changes(): void
    {
        $this->actingWith(['user.view']);
        $this->getJson('/api/users')->assertOk();
        $this->postJson('/api/users', [])->assertForbidden();
    }
}
