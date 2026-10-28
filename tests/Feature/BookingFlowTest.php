<?php

namespace Tests\Feature;

use App\Models\AddonCategory;
use App\Models\AddonItem;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Supply;
use App\Models\SupplyStock;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\DhlShipmentService;
use App\Services\R2Service;
use App\Services\RateQuoteVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /shipments end to end the way /shipment/create sends it — the carrier is mocked, so
 * nothing is ever booked for real.
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private int $account;

    private Branch $branch;

    private int $carrierCalls = 0;

    private ?string $carrierError = null;

    private ?array $sentShipment = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Palladium', 'code' => 'PLD', 'status' => true]);
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $this->account = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => '560634572', 'status' => true, 'is_api_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->staff = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        $this->staff->branches()->sync([$this->branch->id]);
        Sanctum::actingAs($this->staff);

        $this->mock(R2Service::class, fn ($m) => $m->shouldReceive('upload')->andReturn(['key' => 'k']));
        $this->mock(DhlShipmentService::class, function ($m) {
            $m->shouldReceive('createShipment')->andReturnUsing(function (array $account, array $shipment) {
                $this->sentShipment = $shipment;
                if ($this->carrierError) {
                    throw new \RuntimeException($this->carrierError);
                }
                $this->carrierCalls++;

                return ['trackingNumber' => 'TEST123', 'pieces' => [['trackingNumber' => 'P1']], 'raw' => []];
            });
        });
    }

    /** The draft=4 case: DHL 11,974.59 → the form sends 11,975. */
    private function payload(array $override = []): array
    {
        $quote = [
            'carrier' => 'DHL', 'accountId' => $this->account, 'serviceCode' => 'P', 'currency' => 'THB',
            'negotiated' => 11974.59, 'costNegotiated' => 11191.21,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 6311.22], ['code' => 'TOTAL', 'amount' => 783.38]],
        ];
        $quote['quoteId'] = app(RateQuoteVault::class)->remember($quote, $this->staff);

        return array_replace([
            'agent_account_id' => $this->account, 'carrier' => 'DHL', 'service_code' => 'P', 'service_label' => 'EXPRESS WORLDWIDE',
            'origin' => ['contact_name' => 'Somchai Test', 'postcode' => '10110', 'city' => 'Bangkok', 'address' => '123 Test Road', 'phone' => '0800000000'],
            'destination' => ['contact_name' => 'Test Receiver', 'country' => 'US', 'city' => 'NEW YORK', 'state' => 'NY', 'postcode' => '10035', 'address' => '1 RANDALLS IS', 'phone' => '2125550000'],
            'packages' => [['weight' => 25, 'length' => 10, 'width' => 20, 'height' => 30, 'quantity' => 1, 'is_document' => false]],
            'declared_value_currency' => 'THB',
            'invoice_mode' => 'FORM',
            'invoice_lines' => [['description' => 'General Merchandise', 'quantity' => 1, 'unit_value' => 100, 'country_of_origin' => 'TH']],
            'addon_lines' => [],
            'freight_amount' => 11975, 'addon_total' => 0, 'order_total' => 11975, 'currency' => 'THB',
            'payment_method' => 'DAY',
            'rate_quote' => $quote,
        ], $override);
    }

    public function test_the_rounded_up_freight_books_once_under_the_home_branch(): void
    {
        $res = $this->postJson('/api/shipments', $this->payload())->assertOk();
        $shipment = Shipment::find($res->json('id'));
        $this->assertSame('TEST123', $shipment->tracking_number);
        $this->assertSame($this->branch->id, $shipment->branch_id);
        $this->assertEquals(11975, $shipment->freight_amount);
        $this->assertEquals(11191.21, $shipment->cost_amount);   // from the server's own copy of the quote
        $this->assertSame(1, $this->carrierCalls);
        $this->assertSame(0, SystemAlert::where('source', 'booking')->count());
    }

    public function test_wrong_prices_are_refused_before_the_carrier_and_logged_to_system_alerts(): void
    {
        $this->postJson('/api/shipments', $this->payload(['freight_amount' => 11000, 'order_total' => 11000]))
            ->assertStatus(422)->assertJsonPath('error', 'ค่าขนส่งไม่ตรงกับใบเสนอราคา กรุณากดเช็คราคาใหม่ก่อนจอง');
        $this->postJson('/api/shipments', $this->payload(['order_total' => 12000]))->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, $this->carrierCalls);

        $alert = SystemAlert::where('source', 'booking')->where('message', 'like', '%ค่าขนส่งไม่ตรง%')->first();
        $this->assertNotNull($alert);
        $this->assertSame('DHL', $alert->context['carrier']);
        $this->assertSame($this->staff->id, $alert->context['user_id']);
    }

    public function test_validation_errors_and_carrier_rejections_are_logged_too(): void
    {
        $this->postJson('/api/shipments', $this->payload(['invoice_lines' => []]))->assertStatus(422);
        $this->assertTrue(SystemAlert::where('source', 'booking')->get()->contains(fn ($a) => in_array('invoice_lines', $a->context['fields'] ?? [], true)));

        $this->carrierError = 'DHL: postal code invalid';
        $this->postJson('/api/shipments', $this->payload())->assertStatus(422)->assertJsonPath('error', 'DHL: postal code invalid');
        $this->assertTrue(SystemAlert::where('source', 'booking')->where('message', 'like', '%postal code invalid%')->exists());
    }

    public function test_an_expired_quote_asks_for_a_new_rate_check(): void
    {
        $payload = $this->payload();
        $payload['rate_quote']['quoteId'] = 'gone';
        $this->postJson('/api/shipments', $payload)->assertStatus(422)->assertJsonPath('error', 'ใบเสนอราคาหมดอายุแล้ว กรุณากดเช็คราคาใหม่ก่อนจอง');
        $this->assertSame(0, $this->carrierCalls);
    }

    public function test_packing_supplies_need_stock_and_are_deducted(): void
    {
        $box = Supply::create(['name' => 'Box 2A', 'cost_price' => 10, 'sale_price' => 35]);
        $line = ['supply_id' => $box->id, 'name' => 'Box 2A', 'category' => 'Packing Supplies', 'quantity' => 1, 'unit_price' => 35];
        $withBox = fn () => $this->payload(['addon_lines' => [$line], 'addon_total' => 35, 'order_total' => 12010]);

        $this->postJson('/api/shipments', $withBox())->assertStatus(422)->assertJsonPath('error', '"Box 2A" Stock หมดที่สาขานี้ — เลือกไม่ได้');
        $this->assertSame(0, $this->carrierCalls);

        SupplyStock::create(['supply_id' => $box->id, 'branch_id' => $this->branch->id, 'quantity' => 3]);
        $this->postJson('/api/shipments', $withBox())->assertOk();
        $this->assertSame(2, SupplyStock::where('supply_id', $box->id)->value('quantity'));
    }

    /** ICDV is the carrier's insurance even when sold at our own % — UPSC is the only third party. */
    public function test_carrier_insurance_is_bought_whatever_the_price_type_except_upsc(): void
    {
        $category = AddonCategory::create(['name' => 'Insurance', 'sort_order' => 1]);
        $item = fn (string $name, string $type) => AddonItem::create(['addon_category_id' => $category->id, 'name' => $name, 'carriers' => ['DHL'], 'price_type' => $type, 'price' => $type === 'PERCENT' ? 0.4 : null, 'trigger_type' => 'MANUAL', 'status' => true]);
        $cases = [[$item('ICDV (Non Silver/Other)', 'PERCENT'), true], [$item('UPSC (UPS, Shop)', 'PERCENT'), false], [$item('DHL (Declared Value)', 'API_COST'), true]];

        foreach ($cases as [$insurance, $expected]) {
            $payload = $this->payload(['packages' => [['weight' => 25, 'length' => 10, 'width' => 20, 'height' => 30, 'quantity' => 1, 'is_document' => false, 'declared_value' => 5000, 'insured' => true, 'insurance_addon_item_id' => $insurance->id]]]);
            $this->postJson('/api/shipments', $payload)->assertOk();
            $this->assertSame($expected, $this->sentShipment['packages'][0]['useCarrierInsurance'], $insurance->name);
        }
    }

    public function test_insurance_without_a_declared_value_is_refused(): void
    {
        $this->postJson('/api/shipments', $this->payload(['packages' => [['weight' => 25, 'length' => 10, 'width' => 20, 'height' => 30, 'quantity' => 1, 'is_document' => false, 'declared_value' => 0, 'insured' => true]]]))
            ->assertStatus(422)->assertJsonValidationErrors('packages');
        $this->assertSame(0, $this->carrierCalls);
    }

    public function test_a_double_click_books_only_once(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/shipments', $payload)->assertOk()->json('id');
        $this->assertSame($first, $this->postJson('/api/shipments', $payload)->assertOk()->json('id'));
        $this->assertSame(1, $this->carrierCalls);
    }
}
