<?php

namespace Tests\Feature;

use App\Models\AgentAccount;
use App\Models\ChargeCode;
use App\Models\ChargeFixedOverride;
use App\Models\RateBookRun;
use App\Models\Role;
use App\Models\User;
use App\Services\DhlRateService;
use App\Services\RateBookService;
use App\Services\UpsRateService;
use App\Support\RateBookSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Rate Book must show exactly the price Check Rate (/shipment/create) gives for the same
 * shipment. The fake carriers price whatever packages they're asked for, so any difference in
 * how the two paths build the request, apply Fixed Charges or round shows up here.
 */
class RatePriceParityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{carrier: string, packages: array}> every request the fake carriers received */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $agent = fn ($code) => DB::table('agents')->insertGetId(['agent_name' => $code, 'agent_code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        [$ups, $dhl] = [$agent('UPS'), $agent('DHL')];
        foreach ([[$ups, '0279v4'], [$ups, 'ax3173'], [$dhl, '560634572'], [$dhl, '566833742']] as [$agentId, $username]) {
            $id = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => $username, 'status' => true, 'is_api_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            AgentAccount::find($id)->update(['client_id' => 'x', 'client_secret' => 'x', 'basic_auth_username' => 'x', 'basic_auth_password' => 'x']);
        }
        DB::table('countries')->insert([
            ['iso2' => 'SG', 'name' => 'Singapore', 'ups_zone' => '1', 'dhl_zone' => '1', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['iso2' => 'US', 'name' => 'United States', 'ups_zone' => '5', 'dhl_zone' => '6', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Price = f(the packages actually sent): 97.3 THB per billed kg, split into freight + fuel.
        $price = function (string $carrier, array $packages, int $accountId, string $service) {
            $kg = array_sum(array_map(fn ($p) => max((float) $p['weight'], ($p['length'] ?? 0) * ($p['width'] ?? 0) * ($p['height'] ?? 0) / 5000) * ($p['quantity'] ?? 1), $packages));
            $total = round($kg * 97.3 + 0.37, 2);
            $fuel = $carrier === 'UPS' ? '375' : 'FF';

            return [
                'carrier' => $carrier, 'accountId' => $accountId, 'serviceCode' => $service, 'negotiated' => $total, 'published' => $total * 2,
                'billedWeight' => $kg, 'error' => null,
                'chargeBreakdown' => [['code' => 'BASE', 'amount' => round($total * 0.7, 2)], ['code' => $fuel, 'amount' => round($total - round($total * 0.7, 2), 2)]],
            ];
        };
        $this->mock(UpsRateService::class, function ($m) use ($price) {
            $m->shouldReceive('serviceLabels')->andReturn(['65' => 'Saver', '08' => 'Expedited']);
            $m->shouldReceive('quoteAccounts')->andReturnUsing(function ($accounts, $shipment, $codes) use ($price) {
                $this->asked[] = ['carrier' => 'UPS', 'packages' => $shipment['packages']];

                return collect($accounts)->flatMap(fn ($a) => array_map(fn ($c) => $price('UPS', $shipment['packages'], $a['id'], (string) $c), $codes))->all();
            });
        });
        $this->mock(DhlRateService::class, function ($m) use ($price) {
            $m->shouldReceive('quoteAccounts')->andReturnUsing(function ($accounts, $shipment) use ($price) {
                $this->asked[] = ['carrier' => 'DHL', 'packages' => $shipment['packages']];

                return collect($accounts)->flatMap(fn ($a) => [$price('DHL', $shipment['packages'], $a['id'], 'P'), $price('DHL', $shipment['packages'], $a['id'], 'D')])->all();
            });
        });

        // A Fixed Charge on one account's freight — both paths must apply it the same way.
        $base = ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => 'BASE'], ['label' => 'FREIGHT']);
        $account = AgentAccount::where('username_acc', '0279v4')->value('id');
        ChargeFixedOverride::create(['agent_account_id' => $account, 'charge_code_id' => $base->id, 'override_type' => 'FIXED', 'fixed_amount' => 2500, 'unit' => 'THB', 'status' => true]);
    }

    public function test_rate_book_prices_equal_check_rate_prices(): void
    {
        $run = RateBookRun::create(['status' => 'running', 'trigger' => 'manual', 'settings' => RateBookSettings::get()]);
        app(RateBookService::class)->run($run);
        $this->assertSame('success', $run->refresh()->status);

        $user = User::factory()->create(['can_access_all_branches' => true]);
        $user->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        Sanctum::actingAs($user);

        $rows = $run->rows()->whereNull('error')->where('package_type', 'box')->where('zone', '1')->get()
            ->filter(fn ($r) => in_array((float) $r->weight, [0.5, 5.0, 10.5, 21.0, 45.0, 71.0, 150.0], true));
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $pieces = max(1, (int) ceil($row->weight / 70));
            $base = $pieces > 1 ? floor($row->weight / $pieces) : (float) $row->weight;
            $heavier = $pieces > 1 ? (int) round($row->weight - $base * $pieces) : 0;
            $box = fn ($w) => ['weight' => $w, 'length' => 10, 'width' => 10, 'height' => 10, 'is_document' => false];
            $packages = [...array_fill(0, $heavier, $box($base + 1)), ...array_fill(0, $pieces - $heavier, $box($base))];

            $results = $this->postJson('/api/shipping/check-rate', [
                'origin_postcode' => '10110', 'origin_city' => 'Bangkok', 'origin_address' => 'x',
                'destination_country' => 'SG', 'destination_city' => 'Singapore', 'destination_postcode' => '018956',
                'packages' => $packages, 'carriers' => [$row->carrier], 'agent_account_ids' => [$row->agent_account_id],
                'service_codes' => [$row->service_code],
            ])->assertOk()->json('results');
            $quote = collect($results)->first(fn ($r) => ($r['serviceCode'] ?? null) === $row->service_code && empty($r['error']));
            $this->assertNotNull($quote, "{$row->carrier} {$row->weight} kg: no Check Rate result");

            // What Create Shipment charges: the negotiated total less cost-only lines, rounded up to a whole baht.
            $costOnly = collect($quote['chargeBreakdown'] ?? [])->whereIn('code', ['400', 'II', 'IB'])->sum('amount');
            $createPrice = ceil(round($quote['negotiated'] - $costOnly, 2));
            $bookPrice = $row->is_per_kg ? $row->sell * $row->weight : $row->sell;
            $this->assertEqualsWithDelta($createPrice, $bookPrice, $row->is_per_kg ? 0.01 * $row->weight : 0.001,
                "{$row->carrier} {$row->service_code} {$row->weight} kg: Create Shipment {$createPrice} ≠ Rate Book {$bookPrice}");
        }
    }
}
