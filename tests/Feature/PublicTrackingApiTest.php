<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Shipment;
use App\Services\DhlTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicTrackingApiTest extends TestCase
{
    use RefreshDatabase;

    private int $calls = 0;

    private string $key;

    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = ApiClient::create(['name' => 'Web', 'origin_city' => 'Bangkok', 'origin_postcode' => '10110', 'max_results' => 6, 'price_rounding' => 0, 'rate_limit_per_minute' => 60, 'end_user_limit_per_minute' => 0, 'status' => true]);
        $this->key = $this->client->issueKey();

        $this->mock(DhlTrackingService::class, function ($m) {
            $m->shouldReceive('trackByNumber')->andReturnUsing(function () {
                $this->calls++;

                return ['packages' => [[
                    'currentStatusCode' => 'transit',
                    'currentStatusDescription' => 'Processed at BANGKOK',
                    'scheduledDeliveryDate' => '2026-10-03',
                    'activities' => [
                        ['date' => '2026-09-30', 'time' => '10:00:00', 'description' => 'Shipment picked up', 'statusCode' => 'PU', 'location' => 'BANGKOK - THAILAND'],
                        ['date' => '2026-09-30', 'time' => '18:00:00', 'description' => 'Processed', 'statusCode' => 'PL', 'location' => 'BANGKOK - THAILAND'],
                    ],
                ]]];
            });
        });
    }

    private function shipment(string $mode = 'production', string $status = 'booked'): Shipment
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL'.random_int(1, 9999), 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'basic_auth_username' => 'u', 'basic_auth_password' => 'p', 'mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);

        return Shipment::create([
            'agent_account_id' => $accountId, 'carrier' => 'DHL', 'service_code' => 'P', 'service_label' => 'EXPRESS WORLDWIDE', 'status' => $status,
            'tracking_number' => '5084355500', 'origin' => ['country' => 'TH', 'name' => 'Somchai', 'phone' => '0812345678'],
            'destination' => ['country' => 'SG', 'name' => 'Receiver Lee', 'address1' => '1 Secret Rd'], 'packages' => [], 'order_total' => 1500,
        ]);
    }

    public function test_returns_status_and_events_without_personal_data(): void
    {
        $this->shipment();

        $res = $this->withToken($this->key)->getJson('/api/public/v1/tracking/5084 355500')->assertOk()
            ->assertJsonPath('status', 'in_transit')
            ->assertJsonPath('status_text', 'อยู่ระหว่างขนส่ง')
            ->assertJsonPath('destination_country', 'SG')
            ->assertJsonPath('events.0.description', 'Processed'); // newest first

        foreach (['Somchai', '0812345678', 'Receiver Lee', 'Secret Rd', '1500'] as $private) {
            $this->assertStringNotContainsString($private, $res->getContent());
        }
        $this->assertNotNull($res->json('picked_up_at'));

        $this->withToken($this->key)->getJson('/api/public/v1/tracking/5084355500')->assertOk();
        $this->assertSame(1, $this->calls); // cached
        $this->assertSame(['tracking', 'tracking'], ApiRequestLog::pluck('endpoint')->all());
    }

    public function test_unknown_test_mode_and_voided_shipments(): void
    {
        $this->withToken($this->key)->getJson('/api/public/v1/tracking/1234567890')->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $test = $this->shipment('test');
        $this->withToken($this->key)->getJson('/api/public/v1/tracking/5084355500')->assertNotFound();

        $test->agentAccount->update(['mode' => 'production']);
        $test->update(['status' => 'voided']);
        $this->withToken($this->key)->getJson('/api/public/v1/tracking/5084355500')->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('events', []);
        $this->assertSame(0, $this->calls);
    }

    public function test_browser_calls_are_allowed_only_from_registered_origins(): void
    {
        $this->shipment();
        $this->client->update(['browser_origins' => ['https://madd.co.th']]);

        $this->withHeader('Origin', 'https://evil.example')->getJson('/api/public/v1/web/tracking/5084355500')->assertForbidden();
        $this->getJson('/api/public/v1/web/tracking/5084355500')->assertForbidden(); // no Origin

        $this->withHeader('Origin', 'https://MADD.co.th')->getJson('/api/public/v1/web/tracking/5084355500')
            ->assertOk()->assertJsonPath('status', 'in_transit')
            ->assertHeader('Access-Control-Allow-Origin', 'https://madd.co.th');
        $this->assertSame('web_tracking', ApiRequestLog::latest('id')->value('endpoint'));

        $this->client->update(['allow_tracking' => false]);
        $this->withHeader('Origin', 'https://madd.co.th')->getJson('/api/public/v1/web/tracking/5084355500')->assertForbidden();
    }

    public function test_endpoint_can_be_disabled_per_key(): void
    {
        $this->shipment();
        $this->client->update(['allow_tracking' => false]);
        $this->withToken($this->key)->getJson('/api/public/v1/tracking/5084355500')->assertForbidden();

        $this->client->update(['allow_tracking' => true, 'allow_rates' => false]);
        $this->withToken($this->key)->postJson('/api/public/v1/rates', [])->assertForbidden();
    }
}
