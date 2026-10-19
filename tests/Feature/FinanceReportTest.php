<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use App\Models\IntegrationSetting;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions, array $fieldAccess = []): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => $fieldAccess, 'data_scopes' => ['shipment' => 'all']]);
        $user = User::factory()->create(['can_access_all_branches' => true]);
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_revenue_expense_and_profit_use_invoiced_cost_first(): void
    {
        $branch = Branch::create(['name' => 'HQ', 'code' => 'HQ']);
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $live = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => 'live', 'mode' => 'production', 'created_at' => now(), 'updated_at' => now()]);
        $test = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => 'test', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (array $attrs) => Shipment::create($attrs + [
            'agent_account_id' => $live, 'branch_id' => $branch->id, 'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'booked',
            'origin' => [], 'destination' => [], 'packages' => [], 'freight_amount' => 0, 'currency' => 'THB',
        ]);
        $invoiced = $make(['order_total' => 1000, 'cost_amount' => 700, 'cost_currency' => 'THB', 'tracking_number' => 'A1']);
        $make(['order_total' => 500, 'cost_amount' => 300, 'cost_currency' => 'THB']);       // estimated
        $make(['order_total' => 200]);                                                      // no cost known
        $make(['order_total' => 99, 'currency' => 'USD']);                                  // not mixed in
        $make(['order_total' => 5000, 'agent_account_id' => $test]);                        // test mode — left out
        $make(['order_total' => 9999, 'status' => 'voided']);                               // not booked
        $invoice = CarrierInvoice::create(['carrier' => 'DHL', 'storage_key' => 'x', 'total_amount' => 650]);
        CarrierInvoiceLine::create(['carrier_invoice_id' => $invoice->id, 'shipment_id' => $invoiced->id, 'amount' => 600, 'is_matched' => true]);
        CarrierInvoiceLine::create(['carrier_invoice_id' => $invoice->id, 'shipment_id' => $invoiced->id, 'amount' => 40, 'override_amount' => 50, 'is_matched' => true]);

        $this->actingWith(['report.finance'], ['shipment' => ['cost' => 'view', 'pricing' => 'view']]);
        $res = $this->getJson('/api/reports/finance?group_by=branch')->assertOk();
        $res->assertJsonPath('summary.shipments', 4)
            ->assertJsonPath('summary.revenue', 1700)
            ->assertJsonPath('summary.cost_invoiced', 650)   // the carrier's bill, override wins
            ->assertJsonPath('summary.cost_estimated', 300)
            ->assertJsonPath('summary.expense', 950)
            ->assertJsonPath('summary.profit', 750)
            ->assertJsonPath('summary.no_cost_count', 1)
            ->assertJsonPath('summary.other_currency_count', 1)
            ->assertJsonPath('groups.0.label', 'HQ');

        // A Role that can't see cost gets revenue only.
        $this->actingWith(['report.finance']);
        $this->getJson('/api/reports/finance')->assertOk()
            ->assertJsonPath('show_cost', false)
            ->assertJsonPath('summary.revenue', 1700)
            ->assertJsonMissingPath('summary.profit')
            ->assertJsonMissingPath('summary.cost_invoiced');

        $this->actingWith(['shipment.view']);
        $this->getJson('/api/reports/finance')->assertForbidden();
        // The receipt page loads exactly the shipments picked.
        $ids = collect($this->getJson('/api/shipments?ids='.$invoiced->id)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$invoiced->id], $ids);
    }

    public function test_partial_setting_saves_keep_each_others_changes(): void
    {
        IntegrationSetting::mutateJson('t.key', fn ($all) => ['a' => 1] + $all);
        IntegrationSetting::mutateJson('t.key', fn ($all) => ['b' => 2] + $all);
        $this->assertSame(['b' => 2, 'a' => 1], json_decode(IntegrationSetting::get('t.key'), true));
    }
}
