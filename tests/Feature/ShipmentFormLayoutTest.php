<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_admin_arranges_and_everyone_who_books_reads_it(): void
    {
        $this->actingWith(['config.shipment_layout']);
        $this->getJson('/api/shipment-form-layout')->assertOk()->assertJsonPath('origin', [])->assertJsonPath('destination', []);

        $fields = [['key' => 'destination.country', 'width' => 'full'], ['key' => 'destination.contact_name', 'width' => 'half']];
        $this->putJson('/api/shipment-form-layout', ['section' => 'destination', 'fields' => $fields])->assertOk()
            ->assertJsonPath('destination', $fields)
            ->assertJsonPath('origin', []);
        $this->putJson('/api/shipment-form-layout', ['section' => 'destination', 'fields' => [['key' => 'x', 'width' => 'third']]])->assertStatus(422);
        $this->putJson('/api/shipment-form-layout', ['section' => 'payment', 'fields' => []])->assertStatus(422);

        $this->actingWith(['shipment.create']); // staff get the layout, can't change it
        $this->getJson('/api/shipment-form-layout')->assertOk()->assertJsonPath('destination.0.key', 'destination.country');
        $this->putJson('/api/shipment-form-layout', ['section' => 'origin', 'fields' => []])->assertForbidden();
    }
}
