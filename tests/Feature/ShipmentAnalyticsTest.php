<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $mode): int
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'X', 'agent_code' => 'X'.random_int(1, 99999), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ship(int $account, array $attrs): Shipment
    {
        return Shipment::forceCreate($attrs + [
            'agent_account_id' => $account, 'carrier' => 'DHL', 'service_code' => 'P', 'service_label' => 'EXPRESS WORLDWIDE', 'status' => 'booked',
            'tracking_number' => (string) random_int(1000000000, 9999999999), 'origin' => [], 'destination' => ['country' => 'SG'],
            'packages' => [['weight' => 2, 'quantity' => 2]], 'order_total' => 1000, 'created_at' => now()->subDays(2),
        ]);
    }

    private function as(array $permissions, array $fields = []): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => $fields, 'data_scopes' => ['shipment' => 'all']]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_kpis_breakdowns_and_attention(): void
    {
        $live = $this->account('production');
        $this->ship($live, []);
        $this->ship($live, ['carrier' => 'UPS', 'service_label' => 'Saver', 'destination' => ['country' => 'US'], 'order_total' => 3000]);
        $this->ship($live, ['status' => 'voided', 'carrier_cancel_status' => 'pending']);
        $this->ship($live, ['created_at' => now()->subDays(40)]); // previous period
        $this->ship($this->account('test'), []);                 // test mode — ignored

        $this->as(['report.summary'], ['shipment' => ['pricing' => 'view']]);
        $res = $this->getJson('/api/reports/shipment-analytics')->assertOk();

        $res->assertJsonPath('kpis.shipments', 2)
            ->assertJsonPath('kpis.pieces', 4)
            ->assertJsonPath('kpis.weight', 8)
            ->assertJsonPath('kpis.revenue', 4000)
            ->assertJsonPath('kpis.voided', 1)
            ->assertJsonPath('previous_kpis.shipments', 1)
            ->assertJsonPath('group', 'day')
            ->assertJsonPath('destinations.0.key', 'SG')
            ->assertJsonPath('attention.awaiting_pickup.count', 2) // older than 30 days is left out
            ->assertJsonPath('attention.not_invoiced.count', 3)
            ->assertJsonPath('attention.void_not_notified.count', 1);
        $this->assertCount(30, $res->json('trend'));
        $this->assertSame(1, collect($res->json('trend'))->sum('UPS'));

        $this->getJson('/api/reports/shipment-analytics?carrier=UPS')->assertJsonPath('kpis.shipments', 1);
        $this->get('/api/reports/shipment-analytics/export')->assertOk();
    }

    public function test_revenue_needs_the_pricing_field_and_page_needs_permission(): void
    {
        $this->ship($this->account('production'), []);

        $this->as(['report.summary'], ['shipment' => ['pricing' => 'hidden']]);
        $res = $this->getJson('/api/reports/shipment-analytics')->assertOk()->assertJsonPath('show_revenue', false);
        $this->assertArrayNotHasKey('revenue', $res->json('kpis'));
        $this->assertArrayNotHasKey('revenue', $res->json('carriers.0'));

        $this->as(['shipment.view']);
        $this->getJson('/api/reports/shipment-analytics')->assertForbidden();
    }
}
