<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Supply;
use App\Models\SupplyStock;
use App\Services\SupplyStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyShortageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_cannot_take_more_packing_supplies_than_the_branch_holds(): void
    {
        $branch = Branch::create(['name' => 'Banglampoo', 'code' => 'BLP', 'status' => true]);
        $box = Supply::create(['name' => 'Box 2A', 'cost_price' => 10, 'sale_price' => 20]);
        $metal = Supply::create(['name' => 'Metal 10KG', 'cost_price' => 100, 'sale_price' => 200]);
        SupplyStock::create(['supply_id' => $box->id, 'branch_id' => $branch->id, 'quantity' => 2]);
        $service = app(SupplyStockService::class);
        $line = fn (Supply $s, $qty) => ['supply_id' => $s->id, 'name' => $s->name, 'quantity' => $qty];

        $this->assertNull($service->shortage([$line($box, 1), $line($box, 1)], $branch->id));
        $this->assertNull($service->shortage([['name' => 'Form', 'quantity' => 5]], $branch->id)); // not a supply
        $this->assertStringContainsString('เหลือ Stock 2', $service->shortage([$line($box, 3)], $branch->id));
        $this->assertStringContainsString('Stock หมด', $service->shortage([$line($metal, 1)], $branch->id)); // no stock row = 0
    }
}
