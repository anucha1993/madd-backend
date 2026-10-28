<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A box/supply sale over the counter gets a Cash Receipt + Tax Invoice with no shipment at all. */
class SupplySaleReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_can_be_issued_without_a_shipment_only_when_asked(): void
    {
        $branch = Branch::create(['name' => 'Palladium', 'code' => 'PLD', 'status' => true]);
        $role = Role::create(['key' => 'cashier', 'name' => 'C', 'permissions' => ['receipt.create'], 'field_access' => [], 'data_scopes' => ['shipment' => 'all', 'receipt' => 'all']]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);

        $payload = ['branch_id' => $branch->id, 'buyer_name' => 'WALK-IN', 'lines' => [['description' => 'Box 2A x 2', 'amount' => 70]]];

        $this->postJson('/api/receipts', $payload)->assertJsonValidationErrors('shipment_ids');

        $this->postJson('/api/receipts', $payload + ['no_shipment' => true])->assertCreated()
            ->assertJsonPath('tax_invoice.grand_total', '74.90');
        $this->assertSame(2, Receipt::count());
        $this->assertSame(0, Receipt::first()->shipments()->count());
    }
}
