<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargeLineOrderTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_order_is_saved_per_carrier_and_readable_by_everyone(): void
    {
        $this->actingWith(['config.charge_names']);
        $this->getJson('/api/charge-line-order')->assertOk()->assertJsonPath('UPS', [])->assertJsonPath('DHL', []);

        $lines = [['code' => 'BASE', 'pin' => 'top'], ['code' => '375', 'pin' => null], ['code' => 'TOTAL', 'pin' => 'bottom']];
        $this->putJson('/api/charge-line-order', ['carrier' => 'UPS', 'lines' => $lines])->assertOk()
            ->assertJsonPath('UPS', $lines)
            ->assertJsonPath('DHL', []);
        $this->putJson('/api/charge-line-order', ['carrier' => 'UPS', 'lines' => [['code' => 'BASE', 'pin' => 'middle']]])->assertStatus(422);
        $this->putJson('/api/charge-line-order', ['carrier' => 'UPS', 'lines' => [['code' => 'BASE'], ['code' => 'BASE']]])->assertStatus(422);

        $this->actingWith(['shipment.create']); // the booking form reads it, can't change it
        $this->getJson('/api/charge-line-order')->assertOk()->assertJsonPath('UPS.2.code', 'TOTAL');
        $this->putJson('/api/charge-line-order', ['carrier' => 'UPS', 'lines' => []])->assertForbidden();
    }
}
