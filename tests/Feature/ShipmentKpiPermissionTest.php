<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentKpiPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_summary_card_follows_its_permission(): void
    {
        $role = Role::create(['key' => 'kpi', 'name' => 'KPI', 'permissions' => ['shipment.view', 'shipment_kpi.today', 'shipment_kpi.revenue'], 'field_access' => ['shipment' => ['pricing' => 'view']], 'data_scopes' => ['shipment' => 'all']]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/shipments/stats')->assertOk()->assertExactJson([
            'today_count' => 0, 'month_count' => null, 'month_revenue' => 0, 'in_transit_count' => null, 'cancelled_count' => null,
        ]);
    }

    public function test_seeded_roles_keep_every_card(): void
    {
        $this->assertContains('shipment_kpi.revenue', Role::where('key', 'staff')->first()->permissions);
        $this->assertContains('shipment_kpi.in_transit', Role::where('key', 'accounting')->first()->permissions);
    }
}
