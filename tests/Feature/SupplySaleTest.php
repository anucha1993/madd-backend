<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Supply;
use App\Models\SupplySale;
use App\Models\SupplyStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Over-the-counter box sales: the selling branch's stock goes down, a void puts it back. */
class SupplySaleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Supply $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Palladium', 'code' => 'PLD', 'status' => true, 'company_name' => 'MADD Co., Ltd.']);
        $this->box = Supply::create(['name' => 'Box 2A', 'cost_price' => 10, 'sale_price' => 35]);
        $role = Role::create(['key' => 'seller', 'name' => 'S', 'permissions' => ['supply_sale.view', 'supply_sale.create', 'supply_sale.print', 'supply_sale.void'], 'field_access' => [], 'data_scopes' => ['supply_sale' => 'branch']]);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->roles()->sync([$role->id]);
        $user->branches()->sync([$this->branch->id]);
        Sanctum::actingAs($user);
    }

    private function sell(int $qty)
    {
        return $this->postJson('/api/supply-sales', [
            'branch_id' => $this->branch->id,
            'payment_method' => 'เงินสด',
            'lines' => [['supply_id' => $this->box->id, 'quantity' => $qty, 'unit_price' => 35]],
        ]);
    }

    public function test_a_sale_deducts_the_branch_stock_and_a_void_returns_it(): void
    {
        $this->sell(1)->assertStatus(422)->assertJsonPath('error', '"Box 2A" Stock หมดที่สาขานี้ — เลือกไม่ได้');

        SupplyStock::create(['supply_id' => $this->box->id, 'branch_id' => $this->branch->id, 'quantity' => 5]);
        $this->sell(6)->assertStatus(422);

        $sale = $this->sell(2)->assertCreated()->assertJsonPath('total', '70.00')->json();
        $this->assertStringStartsWith('SS', $sale['sale_no']);
        $this->assertSame(3, SupplyStock::first()->quantity);
        $this->getJson('/api/supply-sales')->assertJsonPath('total', 1)->assertJsonPath('issued_total', 70);

        $this->postJson("/api/supply-sales/{$sale['id']}/void")->assertOk()->assertJsonPath('status', 'VOIDED');
        $this->assertSame(5, SupplyStock::first()->quantity);
        $this->getJson('/api/supply-sales')->assertJsonPath('issued_total', 0);
        $this->postJson("/api/supply-sales/{$sale['id']}/void")->assertStatus(422);
        $this->assertSame(5, SupplyStock::first()->quantity);
    }

    public function test_the_thermal_receipt_is_an_80mm_pdf(): void
    {
        SupplyStock::create(['supply_id' => $this->box->id, 'branch_id' => $this->branch->id, 'quantity' => 5]);
        $id = $this->sell(2)->json('id');

        $pdf = $this->get("/api/supply-sales/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        // MediaBox width 80 mm = 226.77 pt.
        $this->assertMatchesRegularExpression('/MediaBox \[0(\.0+)? 0(\.0+)? 226\.77/', $pdf);
        $this->assertSame(1, SupplySale::count());
    }

    public function test_even_an_administrator_cannot_sell_without_stock_and_the_address_is_kept(): void
    {
        $admin = Role::create(['key' => 'boss', 'name' => 'Boss', 'permissions' => [], 'field_access' => [], 'data_scopes' => [], 'is_super_admin' => true]);
        $user = User::factory()->create();
        $user->roles()->sync([$admin->id]);
        Sanctum::actingAs($user);

        $this->sell(1)->assertStatus(422);

        SupplyStock::create(['supply_id' => $this->box->id, 'branch_id' => $this->branch->id, 'quantity' => 1]);
        $this->postJson('/api/supply-sales', [
            'branch_id' => $this->branch->id,
            'customer_name' => 'Somchai',
            'customer_address' => '123 Test Road, Bangkok 10110',
            'customer_phone' => '0800000000',
            'lines' => [['supply_id' => $this->box->id, 'quantity' => 1, 'unit_price' => 35]],
        ])->assertCreated()->assertJsonPath('customer_address', '123 Test Road, Bangkok 10110')->assertJsonPath('customer_phone', '0800000000');
    }

    public function test_a_typed_in_item_sells_without_touching_stock(): void
    {
        SupplyStock::create(['supply_id' => $this->box->id, 'branch_id' => $this->branch->id, 'quantity' => 2]);
        $this->postJson('/api/supply-sales', [
            'branch_id' => $this->branch->id,
            'lines' => [
                ['supply_id' => $this->box->id, 'quantity' => 1, 'unit_price' => 35],
                ['name' => 'เทปใส 2 นิ้ว', 'quantity' => 3, 'unit_price' => 20],
            ],
        ])->assertCreated()->assertJsonPath('total', '95.00')->assertJsonPath('lines.1.name', 'เทปใส 2 นิ้ว')->assertJsonPath('lines.1.supply_id', null);
        $this->assertSame(1, SupplyStock::first()->quantity);

        $this->postJson('/api/supply-sales', ['branch_id' => $this->branch->id, 'lines' => [['quantity' => 1, 'unit_price' => 20]]])
            ->assertJsonValidationErrors('lines.0.name');
    }

    public function test_another_branch_cannot_be_sold_from(): void
    {
        $other = Branch::create(['name' => 'Other', 'code' => 'OTH', 'status' => true]);
        $this->postJson('/api/supply-sales', ['branch_id' => $other->id, 'lines' => [['supply_id' => $this->box->id, 'quantity' => 1, 'unit_price' => 35]]])
            ->assertJsonValidationErrors('branch_id');
    }
}
