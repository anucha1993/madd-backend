<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WeightMarkupBand;
use App\Services\ChargeMarkupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeightMarkupTest extends TestCase
{
    use RefreshDatabase;

    private int $account;

    private int $otherAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $this->account = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => 'ax3173', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherAccount = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => '0279v4', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('countries')->insert([
            ['iso2' => 'US', 'name' => 'United States', 'ups_zone' => '5', 'dhl_zone' => '6', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['iso2' => 'CA', 'name' => 'Canada', 'ups_zone' => '5', 'dhl_zone' => '6', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['iso2' => 'SG', 'name' => 'Singapore', 'ups_zone' => '1', 'dhl_zone' => '1', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $role = Role::create(['key' => 'm', 'name' => 'M', 'permissions' => ['config.weight_markups', 'config.weight_markups_edit'], 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    private function quote(float $billedWeight, string $country, int $accountId, bool $document = false): array
    {
        $result = [
            'carrier' => 'UPS', 'accountId' => $accountId, 'negotiated' => 1000.0, 'published' => 2000.0, 'billedWeight' => $billedWeight,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 1000.0]], 'error' => null,
        ];

        return app(ChargeMarkupService::class)->applyToResults([$result], [['weight' => $billedWeight, 'quantity' => 1, 'isDocument' => $document]], $country)[0];
    }

    public function test_seeded_standard_bands_change_nothing_until_a_rate_is_set(): void
    {
        $this->assertSame(8, WeightMarkupBand::where('carrier', 'UPS')->where('package_type', 'box')->count());
        $this->assertSame(1000.0, $this->quote(3, 'US', $this->account)['negotiated']);
    }

    public function test_rate_per_kg_by_band_with_zone_and_country_overrides(): void
    {
        $bands = [
            ['min_weight' => 0.1, 'max_weight' => 5, 'rates' => ['all' => 10, 'zone:5' => 20, 'country:US' => 30]],
            ['min_weight' => 5.01, 'max_weight' => 10, 'rates' => ['all' => 8]],
            ['min_weight' => 10.01, 'max_weight' => null, 'rates' => ['all' => 5]],
        ];
        $this->putJson('/api/weight-markups', ['carrier' => 'UPS', 'package_type' => 'box', 'bands' => $bands])->assertOk()
            ->assertJsonPath('bands.0.rates.country:US', 30);

        $sg = $this->quote(2, 'SG', $this->account);   // default rate
        $this->assertSame(1020.0, $sg['negotiated']);
        $line = collect($sg['chargeBreakdown'])->firstWhere('code', 'WEIGHT_MARKUP');
        $this->assertSame(20.0, $line['amount']);
        $this->assertTrue($line['isCustomCharge']);

        $this->assertSame(1040.0, $this->quote(2, 'CA', $this->account)['negotiated']); // zone 5 override: 20 × 2
        $this->assertSame(1060.0, $this->quote(2, 'US', $this->account)['negotiated']); // country beats zone: 30 × 2
        $this->assertSame(1056.0, $this->quote(7, 'US', $this->account)['negotiated']); // 5.01-10 band: 8 × 7
        $this->assertSame(1500.0, $this->quote(100, 'US', $this->account)['negotiated']); // open band: 5 × 100
        $this->assertSame(1000.0, $this->quote(2, 'US', $this->account, true)['negotiated']); // documents: own bands, no rate
    }

    public function test_an_account_can_have_its_own_set_and_drop_it_again(): void
    {
        $this->putJson('/api/weight-markups', ['carrier' => 'UPS', 'package_type' => 'box', 'bands' => [['min_weight' => 0.1, 'max_weight' => null, 'rates' => ['all' => 10]]]])->assertOk();
        $this->putJson('/api/weight-markups', ['carrier' => 'UPS', 'package_type' => 'box', 'agent_account_id' => $this->account, 'bands' => [['min_weight' => 0.1, 'max_weight' => null, 'rates' => ['all' => 1]]]])
            ->assertOk()->assertJsonPath('account_sets.0', $this->account);

        $this->assertSame(1002.0, $this->quote(2, 'SG', $this->account)['negotiated']);       // own set
        $this->assertSame(1020.0, $this->quote(2, 'SG', $this->otherAccount)['negotiated']);  // carrier-wide

        $this->deleteJson('/api/weight-markups?carrier=UPS&package_type=box&agent_account_id='.$this->account)->assertOk();
        $this->assertSame(1020.0, $this->quote(2, 'SG', $this->account)['negotiated']);
        $this->deleteJson('/api/weight-markups?carrier=UPS&package_type=box')->assertStatus(422); // carrier-wide set stays
    }

    public function test_view_and_edit_are_separate_permissions(): void
    {
        $actAs = function (array $permissions) {
            $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
            $user = User::factory()->create();
            $user->roles()->sync([$role->id]);
            Sanctum::actingAs($user);
        };
        $body = ['carrier' => 'UPS', 'package_type' => 'box', 'bands' => [['min_weight' => 0.1, 'max_weight' => null, 'rates' => ['all' => 1]]]];

        $actAs(['config.markup']); // Mark-up alone no longer opens Weight Markup
        $this->getJson('/api/weight-markups?carrier=UPS&package_type=box')->assertForbidden();

        $actAs(['config.weight_markups']); // view only
        $this->getJson('/api/weight-markups?carrier=UPS&package_type=box')->assertOk();
        $this->putJson('/api/weight-markups', $body)->assertForbidden();

        $actAs(['config.weight_markups', 'config.weight_markups_edit']);
        $this->putJson('/api/weight-markups', $body)->assertOk();
    }

    public function test_invalid_bands_are_refused(): void
    {
        $this->putJson('/api/weight-markups', ['carrier' => 'UPS', 'package_type' => 'box', 'bands' => [
            ['min_weight' => 0.1, 'max_weight' => null, 'rates' => []],
            ['min_weight' => 5, 'max_weight' => 10, 'rates' => []],
        ]])->assertStatus(422);
        $this->putJson('/api/weight-markups', ['carrier' => 'UPS', 'package_type' => 'box', 'bands' => [
            ['min_weight' => 0.1, 'max_weight' => 5, 'rates' => ['zone:5; drop' => 1]],
        ]])->assertStatus(422);
    }
}
