<?php

namespace Tests\Feature;

use App\Models\AgentAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\DhlRateService;
use App\Services\UpsRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountWeightRangeTest extends TestCase
{
    use RefreshDatabase;

    private int $ax;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $account = fn ($username, $min, $max) => DB::table('agent_accounts')->insertGetId([
            'agent_id' => $agent, 'username_acc' => $username, 'client_id' => 'id', 'client_secret' => 'secret', 'status' => true, 'is_api_enabled' => true,
            'rate_min_weight' => $min, 'rate_max_weight' => $max, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ax = $account('ax3173', 0, 10);
        $account('0279v4', 10.01, null);
        DB::table('countries')->insert(['iso2' => 'US', 'name' => 'United States', 'ups_zone' => '5', 'dhl_zone' => '6', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);

        $role = Role::create(['key' => 'r', 'name' => 'R', 'permissions' => ['shipment.create', 'config.agent_accounts'], 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create(['can_access_all_branches' => true]);
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    /** Usernames of the accounts Check Rate asked for this box weight. */
    private function quotedAccounts(float $weight): array
    {
        $asked = [];
        $this->mock(UpsRateService::class, function ($m) use (&$asked) {
            $m->shouldReceive('serviceLabels')->andReturn(['65' => 'Saver']);
            $m->shouldReceive('quoteAccounts')->andReturnUsing(function ($accounts) use (&$asked) {
                $asked = array_column($accounts, 'username_acc');

                return [];
            });
        });
        $this->mock(DhlRateService::class, fn ($m) => $m->shouldReceive('quoteAccounts')->andReturn([]));

        $this->postJson('/api/shipping/check-rate', [
            'origin_postcode' => '10110', 'origin_city' => 'Bangkok', 'origin_address' => '1 Sukhumvit',
            'destination_country' => 'US', 'destination_city' => 'New York',
            'packages' => [['weight' => $weight, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1]],
        ])->assertOk();

        return $asked;
    }

    // One Check Rate per test — the route keeps its resolved controller (and so its mocks) between requests.
    public function test_up_to_10_kg_only_asks_the_0_to_10_account(): void
    {
        $this->assertSame(['ax3173'], $this->quotedAccounts(9.7));    // billed 10
    }

    public function test_over_10_kg_only_asks_the_account_from_10_01(): void
    {
        $this->assertSame(['0279v4'], $this->quotedAccounts(10.1));   // billed 11
    }

    public function test_accounts_without_a_range_are_asked_for_every_weight(): void
    {
        AgentAccount::query()->update(['rate_min_weight' => null, 'rate_max_weight' => null]);
        $this->assertEqualsCanonicalizing(['ax3173', '0279v4'], $this->quotedAccounts(50));
    }

    public function test_the_range_is_saved_and_validated(): void
    {
        $this->putJson("/api/agent-accounts/{$this->ax}", ['rate_min_weight' => 0, 'rate_max_weight' => 5])->assertOk()->assertJsonPath('rate_max_weight', 5);
        $this->putJson("/api/agent-accounts/{$this->ax}", ['rate_max_weight' => 0])->assertStatus(422);  // not above the saved min… 0
        $this->putJson("/api/agent-accounts/{$this->ax}", ['rate_min_weight' => null, 'rate_max_weight' => null])->assertOk();
    }

    /** Services Check Rate returned for this box weight (the fake UPS quotes Saver + Expedited for every account). */
    private function quotedServices(float $weight): array
    {
        $this->mock(UpsRateService::class, function ($m) {
            $m->shouldReceive('serviceLabels')->andReturn(['65' => 'Saver', '08' => 'Expedited']);
            $m->shouldReceive('quoteAccounts')->andReturnUsing(fn ($accounts) => collect($accounts)->flatMap(fn ($a) => [
                ['carrier' => 'UPS', 'accountId' => $a['id'], 'username' => $a['username_acc'], 'serviceCode' => '65', 'negotiated' => 100, 'chargeBreakdown' => [], 'error' => null],
                ['carrier' => 'UPS', 'accountId' => $a['id'], 'username' => $a['username_acc'], 'serviceCode' => '08', 'negotiated' => 90, 'chargeBreakdown' => [], 'error' => null],
            ])->all());
        });
        $this->mock(DhlRateService::class, fn ($m) => $m->shouldReceive('quoteAccounts')->andReturn([]));

        $results = $this->postJson('/api/shipping/check-rate', [
            'origin_postcode' => '10110', 'origin_city' => 'Bangkok', 'origin_address' => '1 Sukhumvit',
            'destination_country' => 'US', 'destination_city' => 'New York',
            'packages' => [['weight' => $weight, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1]],
        ])->assertOk()->json('results');

        return collect($results)->map(fn ($r) => $r['username'].':'.$r['serviceCode'])->sort()->values()->all();
    }

    public function test_a_service_with_its_own_range_is_only_offered_in_it(): void
    {
        // 0279v4: every service from 10.01 kg, but Expedited only 10.01–70.
        $this->putJson('/api/agent-accounts/'.AgentAccount::where('username_acc', '0279v4')->value('id'), [
            'service_weight_ranges' => [['service_code' => '08', 'min' => 10.01, 'max' => 70]],
        ])->assertOk()->assertJsonPath('service_weight_ranges.0.service_code', '08');

        $this->assertSame(['0279v4:08', '0279v4:65'], $this->quotedServices(30));
    }

    public function test_outside_its_range_the_service_is_dropped(): void
    {
        AgentAccount::where('username_acc', '0279v4')->update(['service_weight_ranges' => json_encode([['service_code' => '08', 'min' => 10.01, 'max' => 40]])]);
        $this->assertSame(['0279v4:65'], $this->quotedServices(50)); // Saver still follows the account's 10.01+
    }

    public function test_a_service_range_can_reach_beyond_the_account_range(): void
    {
        // ax3173 is 0–10 kg, but its Expedited may also quote 10.01–70 (the account is then asked too).
        AgentAccount::whereKey($this->ax)->update(['service_weight_ranges' => json_encode([['service_code' => '08', 'min' => 10.01, 'max' => 70]])]);
        $this->assertSame(['0279v4:08', '0279v4:65', 'ax3173:08'], $this->quotedServices(30));
    }

    public function test_service_ranges_are_validated(): void
    {
        $this->putJson("/api/agent-accounts/{$this->ax}", ['service_weight_ranges' => [['service_code' => '08', 'min' => 70, 'max' => 10]]])->assertStatus(422);
        $this->putJson("/api/agent-accounts/{$this->ax}", ['service_weight_ranges' => [['service_code' => '08'], ['service_code' => '08']]])->assertStatus(422);
        $this->putJson("/api/agent-accounts/{$this->ax}", ['service_weight_ranges' => [['service_code' => 'p', 'min' => 5]]])->assertOk()->assertJsonPath('service_weight_ranges.0.service_code', 'P');
        $this->putJson("/api/agent-accounts/{$this->ax}", ['service_weight_ranges' => []])->assertOk()->assertJsonPath('service_weight_ranges', null);
    }

    public function test_under_its_range_a_service_with_a_minimum_charge_is_priced_at_it(): void
    {
        // 0279v4: Expedited 25.01–70, below that sold as 25 kg.
        $this->putJson('/api/agent-accounts/'.AgentAccount::where('username_acc', '0279v4')->value('id'), [
            'service_weight_ranges' => [['service_code' => '08', 'min' => 25.01, 'max' => 70, 'min_charge' => 25]],
        ])->assertOk()->assertJsonPath('service_weight_ranges.0.min_charge', 25);

        $asked = [];
        $this->mock(UpsRateService::class, function ($m) use (&$asked) {
            $m->shouldReceive('serviceLabels')->andReturn(['65' => 'Saver', '08' => 'Expedited']);
            // Price = 100 THB per kg asked, so the weight each quote was made at shows in the price.
            $m->shouldReceive('quoteAccounts')->andReturnUsing(function ($accounts, $shipment, $codes) use (&$asked) {
                $kg = array_sum(array_map(fn ($p) => $p['weight'] * ($p['quantity'] ?? 1), $shipment['packages']));
                $asked[] = [implode(',', $codes), $kg];

                return collect($accounts)->flatMap(fn ($a) => collect($codes)->map(fn ($c) => [
                    'carrier' => 'UPS', 'accountId' => $a['id'], 'username' => $a['username_acc'], 'serviceCode' => (string) $c,
                    'negotiated' => $kg * 100, 'billedWeight' => $kg, 'chargeBreakdown' => [], 'error' => null,
                ]))->all();
            });
        });
        $this->mock(DhlRateService::class, fn ($m) => $m->shouldReceive('quoteAccounts')->andReturn([]));

        $results = collect($this->postJson('/api/shipping/check-rate', [
            'origin_postcode' => '10110', 'origin_city' => 'Bangkok', 'origin_address' => '1 Sukhumvit',
            'destination_country' => 'US', 'destination_city' => 'New York',
            'packages' => [['weight' => 15, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1]],
        ])->assertOk()->json('results'))->keyBy('serviceCode');

        $this->assertEquals(1500, $results['65']['negotiated']);       // Saver at the real 15 kg
        $this->assertEquals(2500, $results['08']['negotiated']);       // Expedited sold as 25 kg
        $this->assertEquals(25, $results['08']['minChargeWeight']);
        $this->assertContainsEquals(['08', 25], $asked);                     // asked again at 25 kg for 08 only
    }

    public function test_a_minimum_charge_needs_a_range_start_and_fits_under_its_end(): void
    {
        $this->putJson("/api/agent-accounts/{$this->ax}", ['service_weight_ranges' => [['service_code' => '08', 'max' => 70, 'min_charge' => 25]]])->assertStatus(422);
        $this->putJson("/api/agent-accounts/{$this->ax}", ['service_weight_ranges' => [['service_code' => '08', 'min' => 25.01, 'max' => 30, 'min_charge' => 40]]])->assertStatus(422);
    }
}
