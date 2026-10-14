<?php

namespace Tests\Feature;

use App\Models\HeavyRateBand;
use App\Models\RateBookRun;
use App\Models\Role;
use App\Models\User;
use App\Services\ChargeMarkupService;
use App\Services\DhlRateService;
use App\Services\RateBookService;
use App\Services\UpsRateService;
use App\Support\RateBookSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeavyRateTest extends TestCase
{
    use RefreshDatabase;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $this->account = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => 'ax3173', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('countries')->insert([
            ['iso2' => 'US', 'name' => 'United States', 'ups_zone' => '5', 'dhl_zone' => '6', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['iso2' => 'SG', 'name' => 'Singapore', 'ups_zone' => '1', 'dhl_zone' => '1', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingWith(['config.heavy_rates', 'config.heavy_rates_edit']);
    }

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    private function quote(float $billedWeight, string $country, array $extraLines = []): array
    {
        $result = [
            'carrier' => 'UPS', 'accountId' => $this->account, 'negotiated' => 9000.0 + array_sum(array_column($extraLines, 'amount')), 'published' => 15000.0, 'billedWeight' => $billedWeight,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 8800.0], ['code' => '400', 'amount' => 200.0], ...$extraLines], 'error' => null,
        ];

        return app(ChargeMarkupService::class)->applyToResults([$result], [['weight' => $billedWeight, 'quantity' => 1]], $country)[0];
    }

    private function setRates(): void
    {
        $this->putJson('/api/heavy-rates', ['carrier' => 'UPS', 'bands' => [
            ['min_weight' => 71, 'max_weight' => 300, 'rates' => ['all' => 150, 'zone:5' => 160]],
            ['min_weight' => 301, 'max_weight' => null, 'rates' => ['all' => 120]],
        ]])->assertOk()->assertJsonPath('bands.0.rates.zone:5', 160);
    }

    public function test_seeded_bands_without_prices_change_nothing(): void
    {
        $this->assertSame(2, HeavyRateBand::where('carrier', 'UPS')->count());
        $this->assertSame(9000.0, $this->quote(80, 'US')['negotiated']);
    }

    public function test_the_set_price_per_kg_replaces_the_sell_price_by_zone(): void
    {
        $this->setRates();

        $us = $this->quote(80, 'US');                         // zone 5 → its own 160/kg
        $this->assertSame(12800.0 + 200.0, $us['negotiated']); // + the cost-only insurance line, kept as is
        $this->assertSame(['HEAVY_RATE', '400'], array_column($us['chargeBreakdown'], 'code'));
        $this->assertSame(9000.0, $us['costNegotiated']);      // carrier cost stays for booking / profit
        $this->assertTrue($us['heavyRate']);

        $this->assertSame(12000.0 + 200.0, $this->quote(80, 'SG')['negotiated']);   // zone 1 → default 150/kg
        $this->assertSame(48000.0 + 200.0, $this->quote(400, 'US')['negotiated']);  // 301+ → 120/kg
        $this->assertSame(9000.0, $this->quote(70, 'US')['negotiated']);            // under 71 kg → the carrier's quote
    }

    public function test_only_the_picked_codes_are_replaced_and_percent_lines_follow(): void
    {
        $this->setRates();
        // The account's "Total" 7% line (a Mark-up rule on a self-defined code) and a Fixed +100 on fuel.
        $total = \App\Models\ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => 'TOTAL'], ['label' => 'Total']);
        \App\Models\MarkupRule::create([
            'agent_id' => DB::table('agent_accounts')->where('id', $this->account)->value('agent_id'),
            'agent_account_id' => $this->account, 'charge_code_id' => $total->id,
            'rule_type' => 'SIMPLE', 'value' => 7, 'unit' => 'PERCENTAGE', 'status' => true,
        ]);

        // Default: freight (BASE) + fuel (375) replaced; remote area (190) and demand (434) stay.
        $quote = $this->quote(80, 'SG', [['code' => '375', 'amount' => 3000.0], ['code' => '190', 'amount' => 500.0], ['code' => '434', 'amount' => 800.0]]);
        $lines = collect($quote['chargeBreakdown'])->keyBy('code');
        $this->assertSame(['HEAVY_RATE', '400', '190', '434', 'TOTAL'], array_map('strval', array_column($quote['chargeBreakdown'], 'code')));
        $this->assertEquals(12000 + 500 + 800, $lines['TOTAL']['markupBase']);        // 7% of the new subtotal
        $this->assertSame(931.0, $lines['TOTAL']['amount']);
        $this->assertSame(12000.0 + 500 + 800 + 931 + 200, $quote['negotiated']);

        // TOTAL replaced too → no 7% line.
        $this->putJson('/api/heavy-rates/codes', ['carrier' => 'UPS', 'replaced_codes' => ['BASE', '375', 'TOTAL']])->assertOk()
            ->assertJsonPath('replaced_codes', ['BASE', '375', 'TOTAL']);
        $quote = $this->quote(80, 'SG', [['code' => '375', 'amount' => 3000.0], ['code' => '190', 'amount' => 500.0]]);
        $this->assertSame(['HEAVY_RATE', '400', '190'], array_column($quote['chargeBreakdown'], 'code'));
    }

    public function test_rate_book_prices_heavy_points_like_check_rate(): void
    {
        $this->setRates();
        $this->mock(UpsRateService::class, fn ($m) => $m->shouldReceive('quoteAccounts')->andReturnUsing(fn ($accounts, $shipment) => [[
            'carrier' => 'UPS', 'accountId' => $accounts[0]['id'], 'serviceCode' => '65', 'negotiated' => 1000.0, 'billedWeight' => 1,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 1000]], 'error' => null,
        ]]));
        $this->mock(DhlRateService::class);

        $settings = RateBookSettings::get();
        $settings['carriers']['DHL']['enabled'] = false;
        $settings['carriers']['UPS']['bands'] = ['document' => [], 'box' => [
            ['min' => 0.1, 'max' => 5, 'step' => 5, 'account_id' => $this->account],
            ['min' => 70.01, 'max' => 99, 'step' => null, 'account_id' => $this->account],
        ]];
        $settings['carriers']['UPS']['zone_countries'] = ['5' => ['iso2' => 'US', 'city' => 'New York', 'postcode' => '10001', 'state_code' => 'NY']];
        $settings['carriers']['UPS']['extra_columns'] = [];
        $run = RateBookRun::create(['status' => 'running', 'trigger' => 'manual', 'settings' => $settings]);
        app(RateBookService::class)->run($run);

        $heavy = $run->rows()->where('weight', 71)->first();
        $this->assertNull($heavy->error);
        $this->assertEquals(160, $heavy->sell);   // per kg, zone 5 — the carrier's BASE replaced
        $this->assertEquals(160, $heavy->freight);
        $this->assertEquals(round(1000 / 71, 2), $heavy->cost);
        $this->assertEquals(1000, $run->rows()->where('weight', 5)->first()->sell); // light points still quoted
    }

    public function test_view_and_edit_are_separate_permissions(): void
    {
        $body = ['carrier' => 'UPS', 'bands' => [['min_weight' => 71, 'max_weight' => null, 'rates' => ['all' => 100]]]];
        $this->actingWith(['config.weight_markups']);
        $this->getJson('/api/heavy-rates?carrier=UPS')->assertForbidden();
        $this->actingWith(['config.heavy_rates']);
        $this->getJson('/api/heavy-rates?carrier=UPS')->assertOk();
        $this->putJson('/api/heavy-rates', $body)->assertForbidden();
    }

    public function test_invalid_bands_are_refused(): void
    {
        $this->putJson('/api/heavy-rates', ['carrier' => 'UPS', 'bands' => [
            ['min_weight' => 71, 'max_weight' => null, 'rates' => []],
            ['min_weight' => 301, 'max_weight' => null, 'rates' => []],
        ]])->assertStatus(422);
        $this->putJson('/api/heavy-rates', ['carrier' => 'UPS', 'bands' => [['min_weight' => 71, 'max_weight' => null, 'rates' => ['all' => 'abc']]]])->assertStatus(422);
        $this->putJson('/api/heavy-rates', ['carrier' => 'UPS', 'bands' => [['min_weight' => 71, 'max_weight' => null, 'rates' => ['country:US' => 1]]]])->assertStatus(422);
    }
}
