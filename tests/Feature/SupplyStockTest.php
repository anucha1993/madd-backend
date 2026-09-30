<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Supply;
use App\Models\SupplyStock;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\SupplyStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplyStockTest extends TestCase
{
    use RefreshDatabase;

    private function branch(string $name): Branch
    {
        return Branch::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'status' => true]);
    }

    private function supply(): Supply
    {
        return Supply::create(['name' => 'Box S', 'type' => 'BOX', 'cost_price' => 10, 'sale_price' => 30, 'status' => true]);
    }

    private function shipment(Branch $branch, Supply $supply, int $qty): Shipment
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL'.random_int(1, 99999), 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'basic_auth_username' => 'u', 'basic_auth_password' => 'p', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);

        return Shipment::forceCreate([
            'agent_account_id' => $accountId, 'service_code' => 'P', 'origin' => [], 'destination' => [], 'packages' => [],
            'carrier' => 'DHL', 'status' => 'booked', 'tracking_number' => '123', 'branch_id' => $branch->id,
            'addon_lines' => [['name' => 'Box S', 'supply_id' => $supply->id, 'quantity' => $qty, 'unit_price' => 30], ['name' => 'Other', 'quantity' => 1, 'unit_price' => 5]],
        ]);
    }

    private function balance(Supply $supply, Branch $branch): int
    {
        return (int) SupplyStock::where('supply_id', $supply->id)->where('branch_id', $branch->id)->value('quantity');
    }

    public function test_shipment_deducts_and_void_returns_idempotently(): void
    {
        $branch = $this->branch('Bangkok');
        $supply = $this->supply();
        $service = app(SupplyStockService::class);
        $service->move($supply->id, $branch->id, 10, 'receive');

        $shipment = $this->shipment($branch, $supply, 3);
        $service->syncShipment($shipment, true);
        $service->syncShipment($shipment, true); // no double deduction
        $this->assertSame(7, $this->balance($supply, $branch));

        $service->syncShipment($shipment, false); // void
        $this->assertSame(10, $this->balance($supply, $branch));
        $service->syncShipment($shipment, true); // unvoid
        $this->assertSame(7, $this->balance($supply, $branch));

        // Booking never blocks: balance can go negative.
        $service->syncShipment($this->shipment($branch, $supply, 9), true);
        $this->assertSame(-2, $this->balance($supply, $branch));
    }

    public function test_low_stock_alert_opens_once_and_resolves_when_restocked(): void
    {
        $branch = $this->branch('Bangkok');
        $supply = $this->supply();
        $service = app(SupplyStockService::class);
        $service->move($supply->id, $branch->id, 10, 'receive');
        $service->setLimits($supply->id, $branch->id, 5, 50);
        $this->assertSame(0, SystemAlert::count());

        $service->move($supply->id, $branch->id, -6, 'adjust');
        $service->move($supply->id, $branch->id, -1, 'adjust');
        $alert = SystemAlert::sole();
        $this->assertSame('supply_low_stock', $alert->source);
        $this->assertSame(1, $alert->occurrences);
        $this->assertSame(46, $alert->context['suggested_order']); // captured when it first fell to Min (4)

        $service->move($supply->id, $branch->id, 20, 'receive');
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_api_scopes_branches_and_reports_the_ledger(): void
    {
        $mine = $this->branch('Bangkok');
        $other = $this->branch('Chiang Mai');
        $supply = $this->supply();
        $manager = User::factory()->create();
        $manager->roles()->sync(Role::where('key', 'branch_manager')->pluck('id'));
        $manager->branches()->sync([$mine->id]);
        Sanctum::actingAs($manager);

        $this->postJson('/api/supply-stock/receive', ['supply_id' => $supply->id, 'branch_id' => $mine->id, 'quantity' => 20, 'reference' => 'PO-1'])->assertCreated();
        $this->postJson('/api/supply-stock/receive', ['supply_id' => $supply->id, 'branch_id' => $other->id, 'quantity' => 20])->assertForbidden();
        app(SupplyStockService::class)->syncShipment($this->shipment($mine, $supply, 4), true);
        $this->postJson('/api/supply-stock/adjust', ['supply_id' => $supply->id, 'branch_id' => $mine->id, 'counted' => 15, 'note' => 'นับจริง'])->assertOk();
        $this->putJson('/api/supply-stock/limits', ['supply_id' => $supply->id, 'branch_id' => $mine->id, 'min_qty' => 5, 'max_qty' => 2])->assertJsonValidationErrors('max_qty');

        $this->getJson('/api/supply-stock')->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('supplies.0.stocks.0.quantity', 15);
        $this->getJson('/api/supply-stock/movements')->assertJsonCount(3, 'data');
        $this->getJson('/api/supply-stock/report')->assertOk()->assertJsonPath('rows.0', [
            'supply_id' => $supply->id, 'supply_name' => 'Box S', 'branch_id' => $mine->id, 'branch_name' => 'Bangkok',
            'opening' => 0, 'received' => 20, 'used' => -4, 'returned' => 0, 'adjusted' => -1,
            'min_qty' => null, 'max_qty' => null, 'closing' => 15, 'level' => 'ok',
        ]);
        $this->get('/api/supply-stock/report/export')->assertOk();

        // Staff can view but not receive.
        $staff = User::factory()->create();
        $staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        $staff->branches()->sync([$mine->id]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/supply-stock')->assertOk();
        $this->postJson('/api/supply-stock/receive', ['supply_id' => $supply->id, 'branch_id' => $mine->id, 'quantity' => 1])->assertForbidden();
    }
}
