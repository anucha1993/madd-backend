<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\RateBookRun;
use App\Models\Role;
use App\Models\User;
use App\Services\DhlRateService;
use App\Services\RateBookService;
use App\Services\UpsRateService;
use App\Support\RateBookSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateBookTest extends TestCase
{
    use RefreshDatabase;

    private int $ax;

    private int $ups;

    private int $dhl;

    protected function setUp(): void
    {
        parent::setUp();
        $upsAgent = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $dhlAgent = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $account = fn ($agent, $username) => DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => $username, 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->ups = $account($upsAgent, '0279v4');
        $this->ax = $account($upsAgent, 'ax3173');
        $this->dhl = $account($dhlAgent, '560634572');
        $account($dhlAgent, '566833742');
        DB::table('countries')->insert([
            ['iso2' => 'SG', 'name' => 'Singapore', 'ups_zone' => '1', 'dhl_zone' => '1', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['iso2' => 'US', 'name' => 'United States', 'ups_zone' => '5', 'dhl_zone' => '6', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_default_settings_follow_the_old_rate_book(): void
    {
        $settings = RateBookSettings::get();
        $upsBox = $settings['carriers']['UPS']['bands']['box'];

        $this->assertSame($this->ax, $upsBox[0]['account_id']);   // 0.10-5 → ax3173
        $this->assertSame($this->ax, $upsBox[1]['account_id']);   // 5.01-10 → ax3173
        $this->assertSame($this->ups, $upsBox[2]['account_id']);  // 10.01-20 → 0279v4
        $this->assertSame($this->ax, $settings['carriers']['UPS']['bands']['document'][0]['account_id']);
        $this->assertSame(['1', '5'], array_map('strval', array_keys($settings['carriers']['UPS']['zone_countries'])));
        $this->assertSame('New York', $settings['carriers']['UPS']['zone_countries']['5']['city']);
        // Extra rate-card columns slot in after the column they name (JP after zone 2 — absent here — goes last).
        $this->assertSame(['1', '5', 'USA PR', 'JP', 'AU'], array_column(RateBookSettings::columns($settings, 'UPS'), 'key'));
        $this->assertSame('5', RateBookSettings::columns($settings, 'UPS')[2]['zone']);
        $this->assertSame(['1', '6', 'AU NZ'], array_column(RateBookSettings::columns($settings, 'DHL'), 'key'));
        $this->assertSame('Zone 6 US CA MX', RateBookSettings::columns($settings, 'DHL')[1]['label']);

        $weights = collect(RateBookService::points($settings))
            ->where('carrier', 'UPS')->where('package_type', 'box')->pluck('weight')->all();
        $this->assertSame(0.5, $weights[0]);
        $this->assertSame(10.0, $weights[19]);                   // every 0.5 kg up to 10
        $this->assertSame([11.0, 12.0], array_slice($weights, 20, 2)); // whole kg above 10
        $this->assertSame(20.0, $weights[29]);
        $this->assertSame([21.0, 45.0, 71.0, 100.0, 300.0], array_slice($weights, 30)); // then per kg

        // DHL zone 7 switches account from 14 kg.
        $rules = $settings['carriers']['DHL']['account_rules'];
        $other = DB::table('agent_accounts')->where('username_acc', '566833742')->value('id');
        $this->assertSame($other, $rules[0]['account_id']);
        $this->assertSame($this->dhl, RateBookService::accountFor($rules, '7', 13, $this->dhl));
        $this->assertSame($other, RateBookService::accountFor($rules, '7', 14, $this->dhl));
        $this->assertSame($this->dhl, RateBookService::accountFor($rules, '6', 30, $this->dhl));

        $dhlDoc = collect(RateBookService::points($settings))->where('carrier', 'DHL')->where('package_type', 'document');
        $this->assertSame([0.5, 1.0, 1.5, 2.0], $dhlDoc->pluck('weight')->values()->all());
        $this->assertSame('D', $dhlDoc->first()['service_code']);
    }

    public function test_schedule_is_due_once_per_period(): void
    {
        $settings = ['enabled' => true, 'frequency' => 'weekly', 'day_of_week' => 1, 'time' => '02:00'];
        $monday = Carbon::parse('2026-10-05 02:30'); // a Monday

        $this->assertTrue(RateBookSettings::isDue($settings, null, $monday));
        $this->assertFalse(RateBookSettings::isDue($settings, '2026-10-05 02:01:00', $monday));
        $this->assertFalse(RateBookSettings::isDue($settings, null, Carbon::parse('2026-10-05 01:00')));
        $this->assertFalse(RateBookSettings::isDue(['enabled' => false] + $settings, null, $monday));
        $this->assertTrue(RateBookSettings::isDue(['frequency' => 'monthly', 'day_of_month' => 1] + $settings, '2026-09-01 03:00:00', Carbon::parse('2026-10-08 09:00')));
    }

    public function test_run_splits_the_sell_price_into_columns_and_per_kg(): void
    {
        $this->mock(UpsRateService::class, function ($m) {
            $m->shouldReceive('quoteAccounts')->andReturnUsing(fn ($accounts, $shipment) => [[
                'carrier' => 'UPS', 'accountId' => $accounts[0]['id'], 'serviceCode' => '65', 'negotiated' => 1000.4, 'published' => 2000,
                'billedWeight' => 1, 'chargeBreakdown' => [
                    ['code' => 'BASE', 'amount' => 700], ['code' => '375', 'amount' => 200.4], ['code' => '434', 'amount' => 14], ['code' => '573', 'amount' => 86],
                ], 'error' => null,
                'raw' => ['published' => ['RateResponse' => ['RatedShipment' => ['BaseServiceCharge' => ['MonetaryValue' => '3500.00']]]]],
            ]]);
        });
        $this->mock(DhlRateService::class, function ($m) {
            // Same optional services as Check Rate's default, or the prices could drift apart.
            $m->shouldReceive('quoteAccounts')->withArgs(fn ($accounts, $shipment) => $shipment['optionalServiceCodes'] === DhlRateService::DEFAULT_OPTIONAL_SERVICE_CODES)->andReturnUsing(fn ($accounts) => [
                ['carrier' => 'DHL', 'accountId' => $accounts[0]['id'], 'serviceCode' => 'Q', 'negotiated' => 9999, 'chargeBreakdown' => [], 'error' => null],
                ['carrier' => 'DHL', 'accountId' => $accounts[0]['id'], 'serviceCode' => 'P', 'negotiated' => 500, 'chargeBreakdown' => [
                    ['code' => 'BASE', 'amount' => 300], ['code' => 'FF', 'amount' => 100], ['code' => 'OF', 'amount' => 50], ['code' => 'NX', 'amount' => 30], ['code' => 'FD', 'amount' => 20],
                ], 'error' => null],
                ['carrier' => 'DHL', 'accountId' => $accounts[0]['id'], 'serviceCode' => 'D', 'negotiated' => 400, 'chargeBreakdown' => [['code' => 'BASE', 'amount' => 400]], 'error' => null],
            ]);
        });

        // 0279v4 sells freight via a Fixed Charge (700 → 900): the 200 is profit, not freight.
        $base = \App\Models\ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => 'BASE'], ['label' => 'FREIGHT']);
        \App\Models\ChargeFixedOverride::create(['agent_account_id' => $this->ups, 'charge_code_id' => $base->id, 'override_type' => 'FIXED', 'fixed_amount' => 900, 'unit' => 'THB', 'status' => true]);

        $run = RateBookRun::create(['status' => 'running', 'trigger' => 'manual', 'settings' => RateBookSettings::get()]);
        app(RateBookService::class)->run($run);
        $run->refresh();

        $this->assertSame('success', $run->status);
        $this->assertSame($run->total_points, $run->done_points);

        $ups = $run->rows()->where('carrier', 'UPS')->where('package_type', 'box')->where('weight', 0.5)->where('zone', '1')->first();
        $this->assertEquals([700, 200.4, 14, 86], [$ups->freight, $ups->fuel, $ups->surge, $ups->other]);
        $this->assertEquals(1001, $ups->sell);   // whole baht, rounded up like Create Shipment
        $this->assertEquals(0, $ups->markup);
        $this->assertEquals(0.6, $ups->rounding); // the round-up is FREE, not markup
        $this->assertNull($ups->vat);             // no VAT on top — same price as Create Shipment
        $this->assertEquals(1001, $ups->total);
        $this->assertSame('ax3173', $ups->account_username);
        $this->assertEquals(3500, $ups->full); // UPS published freight → DISC 80%

        $perKg = $run->rows()->where('carrier', 'UPS')->where('weight', 21)->first();
        $this->assertTrue($perKg->is_per_kg);
        $this->assertEquals(round(1201 / 21, 2), $perKg->sell);
        $this->assertEquals(round(900 / 21, 2), $perKg->freight);       // after the Fixed Charge
        $this->assertEquals(round(1000.4 / 21, 2), $perKg->cost);       // carrier's own total
        $this->assertEquals(0, $perKg->markup);                         // no Mark-up rule
        $this->assertEquals(round(0.6 / 21, 2), $perKg->rounding);
        $this->assertSame('0279v4', $perKg->account_username);

        $dhl = $run->rows()->where('carrier', 'DHL')->where('package_type', 'box')->first();
        $this->assertEquals([300, 100, 50, 30, 20], [$dhl->freight, $dhl->fuel, $dhl->remote, $dhl->peak, $dhl->gogreen]);
        $this->assertEquals(400, $run->rows()->where('carrier', 'DHL')->where('package_type', 'document')->first()->sell);
    }

    public function test_endpoints_need_their_permissions_and_export_downloads(): void
    {
        $this->actingWith(['report.summary']);
        $this->getJson('/api/rate-book/settings')->assertForbidden();

        $this->actingWith(['report.rate_book']);
        $this->getJson('/api/rate-book/settings')->assertOk()->assertJsonPath('settings.carriers.DHL.service_codes.box', 'P');
        $this->postJson('/api/rate-book/sync')->assertForbidden();

        $this->actingWith(['report.rate_book', 'report.rate_book_settings']);
        $this->postJson('/api/rate-book/sync')->assertOk();
        $this->assertNotNull(IntegrationSetting::get(RateBookSettings::REQUESTED_KEY));

        $run = RateBookRun::create(['status' => 'success', 'trigger' => 'manual', 'settings' => RateBookSettings::get(), 'started_at' => now(), 'finished_at' => now()]);
        $run->rows()->create(['carrier' => 'UPS', 'package_type' => 'box', 'zone' => '1', 'country_iso2' => 'SG', 'band_label' => '0.10-5.00 kg', 'weight' => 0.5, 'freight' => 1, 'sell' => 1, 'vat' => 0.07, 'total' => 1.07, 'markup' => 0]);
        $run->rows()->create(['carrier' => 'UPS', 'package_type' => 'box', 'zone' => '1', 'country_iso2' => 'SG', 'band_label' => '20.01-44.00 kg', 'weight' => 21, 'is_per_kg' => true, 'freight' => 100, 'sell' => 110, 'vat' => 7.7, 'total' => 117.7, 'markup' => 10]);
        $run->rows()->create(['carrier' => 'DHL', 'package_type' => 'document', 'zone' => '7', 'country_iso2' => 'GB', 'band_label' => '0.01-2.00 kg', 'weight' => 0.5, 'error' => 'boom']);
        foreach (['UPS', 'DHL'] as $carrier) {
            $response = $this->get("/api/rate-book/runs/{$run->id}/export?carrier={$carrier}");
            $response->assertOk();
            $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
            $this->assertStringContainsString("{$carrier} Rate Book", $response->headers->get('Content-Disposition'));
        }
        $this->get("/api/rate-book/runs/{$run->id}/export?carrier=KEX")->assertStatus(422);

        $this->getJson("/api/rate-book/runs/{$run->id}/rows?carrier=UPS")->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('columns.0.key', '1')
            ->assertJsonPath('columns.0.country', 'Singapore');
        $this->getJson("/api/rate-book/runs/{$run->id}/rows?carrier=DHL")->assertOk()->assertJsonPath('rows.0.error', 'boom');
    }
}
