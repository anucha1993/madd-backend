<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Services\OpenAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAiAssistTest extends TestCase
{
    use RefreshDatabase;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ApiClient::create(['name' => 'Web', 'origin_city' => 'Bangkok', 'origin_postcode' => '10110', 'max_results' => 6, 'price_rounding' => 0, 'rate_limit_per_minute' => 60, 'end_user_limit_per_minute' => 50, 'status' => true, 'allow_rates' => true, 'browser_origins' => ['https://madd.co.th']]);
        $this->mock(OpenAiService::class, function ($m) {
            $m->shouldReceive('isEnabled')->andReturn(true);
            $m->shouldReceive('chatJson')->andReturnUsing(function () {
                $this->calls++;

                return [
                    'destination_country_iso2' => 'jp', 'destination_city' => 'Tokyo<script>', 'destination_postal_code' => null,
                    'shipment_type' => 'parcel', 'understood' => true, 'note' => 'Estimated from 2 pairs of shoes.',
                    'packages' => [
                        ['weight_kg' => 2.4, 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 26, 'quantity' => 1, 'estimated' => true, 'item' => 'shoes'],
                        ['weight_kg' => 999, 'length_cm' => 500, 'quantity' => 0],
                        ['length_cm' => 10],
                    ],
                    'price' => 1234,
                ];
            });
        });
    }

    private function ask(string $text, string $origin = 'https://madd.co.th')
    {
        return $this->withHeader('Origin', $origin)->post('/api/public/v1/web/ai-parse', ['text' => $text]);
    }

    public function test_turns_a_sentence_into_clamped_form_values_without_prices(): void
    {
        $res = $this->ask('2 pairs of shoes to Tokyo')->assertOk()
            ->assertJsonPath('destination.country', 'JP')
            ->assertJsonPath('destination.city', 'Tokyo')
            ->assertJsonPath('packages.0.estimated', true)
            ->assertJsonPath('packages.1.weight', 300)       // clamped
            ->assertJsonPath('packages.1.length', 300)
            ->assertJsonPath('packages.1.quantity', 1)
            ->assertJsonCount(2, 'packages')                  // no weight → dropped
            ->assertHeader('Access-Control-Allow-Origin', 'https://madd.co.th');
        $this->assertStringNotContainsString('1234', $res->getContent());

        $this->ask('2 pairs of shoes  to Tokyo')->assertOk(); // same sentence → cache
        $this->assertSame(1, $this->calls);
        $this->assertSame(['ai_parse', 'ai_parse'], ApiRequestLog::orderBy('id')->pluck('endpoint')->all());
    }

    public function test_needs_a_registered_site_and_caps_each_visitor(): void
    {
        $this->ask('shoes to Tokyo', 'https://evil.example')->assertForbidden();

        foreach (range(1, 5) as $i) {
            $this->ask("item {$i} to Tokyo")->assertOk();
        }
        $this->ask('item 6 to Tokyo')->assertStatus(429);
    }
}
