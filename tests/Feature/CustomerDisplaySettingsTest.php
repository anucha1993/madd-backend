<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_defaults_then_saved_changes(): void
    {
        $this->actingWith(['config.customer_display', 'config.customer_display_edit']);
        $this->getJson('/api/customer-display/settings')->assertOk()
            ->assertJsonPath('sections.recipient', true)
            ->assertJsonPath('sections.contact', false)
            ->assertJsonPath('quotes_mode', 'all');

        $this->putJson('/api/customer-display/settings', ['sections' => ['contact' => true], 'idle' => ['images' => ['https://x.test/a.png', 'https://x.test/b.png']]])->assertOk();
        $this->putJson('/api/customer-display/settings', ['idle' => ['images' => ['https://x.test/c.png']]])->assertOk()
            ->assertJsonPath('sections.contact', true)                       // earlier change kept
            ->assertJsonPath('idle.images', ['https://x.test/c.png'])         // list replaced, not merged
            ->assertJsonPath('idle.title', 'ยินดีต้อนรับ');                  // untouched default

        $this->putJson('/api/customer-display/settings', ['idle' => ['images' => ['javascript:alert(1)']]])->assertStatus(422);
        $this->putJson('/api/customer-display/settings', ['quotes_mode' => 'cost'])->assertStatus(422);
    }

    public function test_the_open_button_permission_reads_but_cannot_change(): void
    {
        $this->actingWith(['shipment.customer_display']);
        $this->getJson('/api/customer-display/settings')->assertOk();
        $this->putJson('/api/customer-display/settings', ['quotes_mode' => 'selected'])->assertForbidden();

        $this->actingWith(['shipment.create']);
        $this->getJson('/api/customer-display/settings')->assertForbidden();
    }
}
