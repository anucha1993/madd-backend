<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicApiStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_views_are_counted_and_summarised_with_searches(): void
    {
        $client = ApiClient::create(['name' => 'Web', 'origin_city' => 'Bangkok', 'origin_postcode' => '10110', 'max_results' => 6, 'price_rounding' => 0, 'rate_limit_per_minute' => 60, 'end_user_limit_per_minute' => 10, 'status' => true, 'browser_origins' => ['https://madd.co.th']]);

        // Beacon from a registered site only.
        $this->withHeader('Origin', 'https://evil.example')->post('/api/public/v1/web/hit', ['page' => 'rates'])->assertForbidden();
        $this->withHeader('Origin', 'https://madd.co.th')->post('/api/public/v1/web/hit', ['page' => 'rates'])->assertNoContent();
        $this->withHeader('Origin', 'https://madd.co.th')->post('/api/public/v1/web/hit', ['page' => 'tracking'])->assertNoContent();

        $log = fn (array $a) => ApiRequestLog::create($a + ['api_client_id' => $client->id, 'end_user_ip' => '1.1.1.1', 'created_at' => now()]);
        $log(['endpoint' => 'web_rates', 'status_code' => 200, 'result_count' => 3, 'destination_country' => 'SG', 'total_weight' => 2, 'lowest_price' => 1200]);
        $log(['endpoint' => 'web_rates', 'status_code' => 200, 'result_count' => 0, 'destination_country' => 'SG', 'total_weight' => 12, 'lowest_price' => null, 'end_user_ip' => '2.2.2.2']);
        $log(['endpoint' => 'web_tracking', 'status_code' => 404]);
        $log(['endpoint' => 'tracking', 'status_code' => 200, 'error' => 'external']);

        $admin = User::factory()->create();
        $admin->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/api-clients/stats')->assertOk();
        $res->assertJsonPath('summary.rates.views', 1)
            ->assertJsonPath('summary.rates.searches', 2)
            ->assertJsonPath('summary.rates.searchers', 2)
            ->assertJsonPath('summary.rates.no_result', 1)
            ->assertJsonPath('summary.tracking.searches', 2)
            ->assertJsonPath('summary.tracking.not_found', 1)
            ->assertJsonPath('summary.tracking.external', 1)
            ->assertJsonPath('destinations.0.country', 'SG')
            ->assertJsonPath('destinations.0.searches', 2);
        $this->assertCount(30, $res->json('daily'));
        $this->assertSame(2, collect($res->json('daily'))->sum('rates_searches'));
        $this->assertSame(4, array_sum($res->json('hourly')));
        $this->assertSame([0, 1, 0, 1, 0], array_column($res->json('weights'), 'searches'));
    }
}
