<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DhlRateService;
use App\Services\UpsRateService;
use App\Support\ManualRateCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualCardQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_accounts')->insert(['agent_id' => $agent, 'username_acc' => 'u', 'client_id' => 'id', 'client_secret' => 'secret', 'status' => true, 'is_api_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::create(['key' => 'r', 'name' => 'R', 'permissions' => ['shipment.create', 'shipment.view'], 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create(['can_access_all_branches' => true]);
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
        $this->mock(UpsRateService::class, function ($m) {
            $m->shouldReceive('serviceLabels')->andReturn(['65' => 'Saver']);
            $m->shouldReceive('quoteAccounts')->andReturn([]);
        });
        $this->mock(DhlRateService::class, fn ($m) => $m->shouldReceive('quoteAccounts')->andReturn([]));
    }

    private function checkRate(string $country, float $weight): array
    {
        return $this->postJson('/api/shipping/check-rate', [
            'origin_postcode' => '10110', 'origin_city' => 'Bangkok', 'origin_address' => '1 Sukhumvit',
            'destination_country' => $country, 'destination_city' => 'Tokyo',
            'packages' => [['weight' => $weight, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1]],
        ])->assertOk()->json('results');
    }

    public function test_sagawa_is_quoted_for_japan_only_and_never_bookable(): void
    {
        $sagawa = collect($this->checkRate('JP', 0.7))->firstWhere('carrier', 'SAGAWA');
        $this->assertNotNull($sagawa);
        $this->assertEquals(1260, $sagawa['negotiated']);   // 0.7 kg → the 1.0 kg step
        $this->assertTrue($sagawa['quoteOnly']);
        $this->assertArrayNotHasKey('quoteId', $sagawa);    // nothing to book with

        $this->assertEquals(25 * 169, collect($this->checkRate('JP', 25))->firstWhere('carrier', 'SAGAWA')['negotiated']);
        $this->assertNull(collect($this->checkRate('US', 5))->firstWhere('carrier', 'SAGAWA'));

        // A booking can only be UPS / DHL.
        $this->postJson('/api/shipments', ['carrier' => 'SAGAWA'])->assertStatus(422)->assertJsonValidationErrors('carrier');
    }

    public function test_card_prices_follow_the_steps_then_per_kg(): void
    {
        $card = ManualRateCards::get()[0];
        $this->assertSame([2700.0, 10.0], ManualRateCards::price($card, 10));
        $this->assertSame([2810.0, 11.0], ManualRateCards::price($card, 10.2));
        $this->assertSame([21 * 169.0, 21.0], ManualRateCards::price($card, 20.3));
        $this->assertSame([250 * 99.0, 250.0], ManualRateCards::price($card, 250));
    }
}
