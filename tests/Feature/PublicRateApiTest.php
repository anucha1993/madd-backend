<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Role;
use App\Models\User;
use App\Services\DhlRateService;
use App\Services\UpsRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicRateApiTest extends TestCase
{
    use RefreshDatabase;

    private int $dhlCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId([
            'agent_id' => $agentId, 'username_acc' => '560634572', 'basic_auth_username' => 'u', 'basic_auth_password' => 'p',
            'mode' => 'test', 'status' => true, 'is_api_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->mock(UpsRateService::class, function ($m) {
            $m->shouldReceive('serviceLabels')->andReturn([]);
            $m->shouldReceive('quoteAccounts')->andReturn([]);
        });
        $this->mock(DhlRateService::class, function ($m) use ($accountId) {
            $m->shouldReceive('quoteAccounts')->andReturnUsing(function () use ($accountId) {
                $this->dhlCalls++;

                return [
                    ['carrier' => 'DHL', 'accountId' => $accountId, 'username' => '560634572', 'serviceCode' => 'P', 'serviceLabel' => 'EXPRESS WORLDWIDE', 'currency' => 'THB', 'published' => 2000.0, 'negotiated' => 1234.4, 'transitDays' => 3, 'estimatedDelivery' => '2026-10-03', 'chargeBreakdown' => [['code' => 'BASE', 'amount' => 1234.4]], 'raw' => ['secret' => 'x']],
                    ['carrier' => 'DHL', 'accountId' => $accountId, 'serviceCode' => 'D', 'error' => 'not available'],
                ];
            });
        });
    }

    private function client(array $attrs = []): array
    {
        $client = ApiClient::create($attrs + ['name' => 'WordPress', 'origin_city' => 'Bangkok', 'origin_postcode' => '10110', 'max_results' => 6, 'price_rounding' => 0, 'rate_limit_per_minute' => 60, 'end_user_limit_per_minute' => 10, 'status' => true]);

        return [$client, $client->issueKey()];
    }

    private function body(array $overrides = []): array
    {
        return $overrides + ['destination' => ['country' => 'sg', 'city' => 'Singapore'], 'shipment_type' => 'parcel', 'packages' => [['weight' => 2, 'length' => 30, 'width' => 20, 'height' => 10]]];
    }

    public function test_returns_only_sell_price_fields(): void
    {
        [, $key] = $this->client();

        $res = $this->withToken($key)->postJson('/api/public/v1/rates', $this->body())->assertOk();

        $res->assertJsonPath('options.0.price', 1234.4)
            ->assertJsonPath('options.0.carrier', 'DHL')
            ->assertJsonPath('destination.country', 'SG')
            ->assertJsonCount(1, 'options');
        $this->assertSame(['carrier', 'service_code', 'service_name', 'price', 'currency', 'transit_days', 'estimated_delivery'], array_keys($res->json('options.0')));
        $this->assertStringNotContainsString('560634572', $res->getContent());
        $this->assertStringNotContainsString('2000', $res->getContent());
        $this->assertSame(200, ApiRequestLog::sole()->status_code);
    }

    public function test_rejects_bad_keys_disabled_clients_and_other_ips(): void
    {
        $this->withToken('madd_wrong')->postJson('/api/public/v1/rates', $this->body())->assertUnauthorized();

        [$client, $key] = $this->client(['allowed_ips' => ['10.0.0.0/8']]);
        $this->withToken($key)->postJson('/api/public/v1/rates', $this->body())->assertForbidden();

        $client->update(['allowed_ips' => null, 'status' => false]);
        $this->withHeader('X-Api-Key', $key)->postJson('/api/public/v1/rates', $this->body())->assertUnauthorized();
    }

    public function test_caches_identical_requests_rounds_and_rate_limits(): void
    {
        [, $key] = $this->client(['price_rounding' => 10, 'end_user_limit_per_minute' => 2]);

        $this->withToken($key)->withHeader('X-End-User-IP', '1.2.3.4')->postJson('/api/public/v1/rates', $this->body())->assertJsonPath('options.0.price', 1240);
        $this->withToken($key)->withHeader('X-End-User-IP', '1.2.3.4')->postJson('/api/public/v1/rates', $this->body())->assertOk();
        $this->assertSame(1, $this->dhlCalls);
        $this->assertTrue(ApiRequestLog::latest('id')->first()->cached);

        $this->withToken($key)->withHeader('X-End-User-IP', '1.2.3.4')->postJson('/api/public/v1/rates', $this->body())->assertStatus(429);
        $this->withToken($key)->withHeader('X-End-User-IP', '5.6.7.8')->postJson('/api/public/v1/rates', $this->body())->assertOk();
    }

    public function test_lists_active_destination_countries(): void
    {
        [, $key] = $this->client();
        DB::table('countries')->insert([['iso2' => 'SG', 'name' => 'Singapore', 'status' => true], ['iso2' => 'TH', 'name' => 'Thailand', 'status' => true], ['iso2' => 'KP', 'name' => 'North Korea', 'status' => false]]);
        $this->withToken($key)->getJson('/api/public/v1/countries')->assertOk()->assertExactJson(['countries' => [['iso2' => 'SG', 'name' => 'Singapore']]]);
    }

    public function test_validation_errors_are_logged(): void
    {
        [, $key] = $this->client();
        $this->withToken($key)->postJson('/api/public/v1/rates', $this->body(['destination' => ['country' => 'TH']]))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
        $this->assertSame(422, ApiRequestLog::sole()->status_code);
    }

    public function test_admin_creates_a_key_that_is_only_stored_hashed(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/api-clients', ['name' => 'Web', 'origin_city' => 'Bangkok', 'origin_postcode' => '10110', 'max_results' => 5, 'price_rounding' => 0, 'rate_limit_per_minute' => 60, 'end_user_limit_per_minute' => 10])->assertCreated();
        $key = $res->json('api_key');
        $this->assertStringStartsWith('madd_', $key);
        $this->assertArrayNotHasKey('key_hash', $res->json('client'));
        $this->assertSame(hash('sha256', $key), DB::table('api_clients')->value('key_hash'));

        $this->getJson('/api/api-clients')->assertOk()->assertJsonMissingPath('0.key_hash');
        $old = $key;
        $new = $this->postJson('/api/api-clients/'.$res->json('client.id').'/regenerate')->json('api_key');
        $this->assertNotSame($old, $new);
        $this->assertNull(ApiClient::findByKey($old));
    }
}
