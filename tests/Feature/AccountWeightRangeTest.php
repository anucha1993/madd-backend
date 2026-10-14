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
}
