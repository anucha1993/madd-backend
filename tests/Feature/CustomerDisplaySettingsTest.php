<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertJsonPath('idle.title', 'Welcome');                  // untouched default

        $this->putJson('/api/customer-display/settings', ['idle' => ['images' => ['javascript:alert(1)']]])->assertStatus(422);
        $this->putJson('/api/customer-display/settings', ['quotes_mode' => 'cost'])->assertStatus(422);
    }

    public function test_a_banner_image_is_uploaded_and_served_publicly(): void
    {
        $this->actingWith(['config.customer_display', 'config.customer_display_edit']);
        $url = $this->post('/api/customer-display/media', ['image' => UploadedFile::fake()->image('promo.png', 1600, 600)], ['Accept' => 'application/json'])
            ->assertOk()->json('url');
        $this->putJson('/api/customer-display/settings', ['banner' => ['image_url' => $url]])->assertOk()->assertJsonPath('banner.image_url', $url);
        $this->assertMatchesRegularExpression('#^/api/customer-display/media/\d+$#', $url); // no host baked in
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actingWith(['config.customer_display']); // view only can't upload
        $this->post('/api/customer-display/media', ['image' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_banner_image_needs_no_login(): void
    {
        $id = \Illuminate\Support\Facades\DB::table('customer_display_media')->insertGetId(['mime' => 'image/png', 'data' => base64_encode('png'), 'size' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->get("/api/customer-display/media/{$id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/customer-display/media/999999')->assertNotFound();
    }

    public function test_the_open_button_permission_reads_but_cannot_change(): void
    {
        $this->actingWith(['shipment.customer_display']);
        $this->getJson('/api/customer-display/settings')->assertOk();
        $this->putJson('/api/customer-display/settings', ['quotes_mode' => 'selected'])->assertForbidden();

        $this->actingWith(['shipment.create']);
        $this->getJson('/api/customer-display/settings')->assertForbidden();
    }

    public function test_a_display_on_another_computer_gets_the_state_by_its_key(): void
    {
        $key = str_repeat('aB3', 10).'xy'; // 32 chars
        $this->actingWith(['shipment.create']);
        $this->putJson("/api/customer-display/live/{$key}", ['state' => ['stage' => 'idle']])->assertForbidden();

        $this->actingWith(['shipment.customer_display']);
        $v = $this->putJson("/api/customer-display/live/{$key}", ['state' => ['stage' => 'quotes', 'total' => ['amount' => 1200]]])->assertOk()->json('v');

        // The display has no login.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/customer-display/live/{$key}")->assertOk()->assertJsonPath('state.total.amount', 1200)->assertJsonPath('v', $v);
        $this->getJson("/api/customer-display/live/{$key}?v={$v}")->assertNoContent(); // nothing newer
        $this->getJson('/api/customer-display/live/'.str_repeat('z', 32))->assertNoContent();
        $this->getJson('/api/customer-display/live/short')->assertNotFound(); // keys must be 32 chars
    }
}
