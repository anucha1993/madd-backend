<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Supply;
use App\Models\User;
use App\Services\SupplyStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    private function shipment(?int $branchId = null): Shipment
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'basic_auth_username' => 'u', 'basic_auth_password' => 'p', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);

        return Shipment::create([
            'agent_account_id' => $accountId, 'branch_id' => $branchId, 'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'booked',
            'tracking_number' => '5084355500', 'origin' => [], 'destination' => [], 'packages' => [], 'cost_amount' => 900, 'order_total' => 1200,
        ]);
    }

    private function user(array $permissions): User
    {
        $role = Role::create([
            'key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions,
            'field_access' => [], 'data_scopes' => ['shipment' => 'all', 'pickup' => 'all', 'receipt' => 'all', 'supply_stock' => 'all'],
        ]);
        $user = User::factory()->create(['name' => 'Somchai']);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_shipment_timeline_follows_permissions_and_hidden_fields(): void
    {
        $branch = Branch::create(['name' => 'Bangkok', 'code' => 'BKK', 'status' => true]);
        $shipment = $this->shipment($branch->id); // created with no user = System
        $shipment->update(['tracking_status' => 'delivered']); // tracking sync = System

        $viewer = $this->user(['shipment.view', 'shipment.label', 'shipment.timeline', 'supply_stock.view']);
        Sanctum::actingAs($viewer);
        $shipment->update(['status' => 'voided', 'void_reason' => 'ลูกค้ายกเลิก', 'cost_amount' => 950]);
        $supply = Supply::create(['name' => 'Box S', 'cost_price' => 1, 'sale_price' => 30, 'status' => true]);
        app(SupplyStockService::class)->move($supply->id, $branch->id, -2, 'shipment', ['shipment_id' => $shipment->id]);
        $this->get("/api/shipments/{$shipment->id}/label");

        $entries = collect($this->getJson("/api/shipments/{$shipment->id}/timeline")->assertOk()->json('entries'));

        $this->assertSame(['created', 'updated', 'updated', 'document_viewed', 'stock_shipment'], $entries->pluck('event')->all());
        $this->assertTrue($entries[0]['is_system']);
        $this->assertArrayNotHasKey('cost_amount', $entries[0]['changes']); // cost hidden by default
        $this->assertSame('delivered', $entries[1]['changes']['tracking_status']['new']);
        $this->assertSame('Somchai', $entries[2]['actor']);
        $this->assertSame(['status', 'void_reason'], array_keys($entries[2]['changes']));
        $this->assertArrayNotHasKey('ip', $entries[2]); // IP only for user.audit

        // No timeline permission → 403.
        Sanctum::actingAs($this->user(['shipment.view']));
        $this->getJson("/api/shipments/{$shipment->id}/timeline")->assertForbidden();
    }

    public function test_logins_are_logged(): void
    {
        $user = User::factory()->create(['username' => 'somchai', 'password' => bcrypt('secret-1')]);

        $this->postJson('/api/login', ['username' => 'somchai', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['username' => 'somchai', 'password' => 'secret-1'])->assertOk();

        $this->assertSame(['login_failed', 'login'], AuditLog::whereIn('event', ['login', 'login_failed'])->orderBy('id')->pluck('event')->all());
        $failed = AuditLog::where('event', 'login_failed')->first();
        $this->assertNull($failed->user_id);
        $this->assertSame($user->id, $failed->subject_id);
        $this->assertSame($user->id, AuditLog::where('event', 'login')->value('user_id'));
    }
}
