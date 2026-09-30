<?php

namespace Tests\Feature;

use App\Models\Pickup;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\DhlPickupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DHL Express has no shipment-cancel API, so a DHL void is tracked in MADD (who / why /
 * "DHL still to be told" -> "DHL confirmed") and frees any pickup that would otherwise
 * still send a courier.
 */
class ShipmentVoidTest extends TestCase
{
    use RefreshDatabase;

    private int $accountId;

    private array $cancelledPickups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $this->accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '123', 'basic_auth_username' => 'u', 'basic_auth_password' => 'p', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);

        $test = $this;
        $this->app->instance(DhlPickupService::class, new class($test) extends DhlPickupService
        {
            public function __construct(private $test)
            {
            }

            public function cancelPickup(array $account, string $dispatchConfirmationNumber, string $requestorName, string $reason): array
            {
                $this->test->recordCancelledPickup($dispatchConfirmationNumber);

                return [];
            }
        });

        $admin = User::factory()->create();
        $admin->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        Sanctum::actingAs($admin);
    }

    public function recordCancelledPickup(string $ref): void
    {
        $this->cancelledPickups[] = $ref;
    }

    private function shipment(): Shipment
    {
        return Shipment::create([
            'agent_account_id' => $this->accountId, 'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'booked',
            'tracking_number' => (string) random_int(1000000000, 9999999999), 'origin' => [], 'destination' => [], 'packages' => [],
        ]);
    }

    private function pickup(string $ref, array $shipments): Pickup
    {
        $pickup = Pickup::forceCreate(['agent_account_id' => $this->accountId, 'carrier' => 'DHL', 'status' => 'requested', 'carrier_reference' => $ref,
            'pickup_date' => now()->addDay()->toDateString(), 'ready_time' => '09:00', 'close_time' => '17:00', 'address' => []]);
        $pickup->shipments()->attach(collect($shipments)->pluck('id'));

        return $pickup;
    }

    public function test_dhl_void_records_who_why_and_pending_carrier_cancellation(): void
    {
        $s = $this->shipment();

        $res = $this->postJson("/api/shipments/{$s->id}/void", ['reason' => 'จองผิด'])->assertOk();

        $res->assertJsonPath('status', 'voided')->assertJsonPath('carrier_cancel_status', 'pending')
            ->assertJsonPath('void_reason', 'จองผิด')->assertJsonPath('pickup_notice', null);
        $this->assertNotNull($res->json('voided_by.name'));
    }

    public function test_void_cancels_a_pickup_only_when_nothing_else_needs_collecting(): void
    {
        $alone = $this->shipment();
        $solo = $this->pickup('SOLO1', [$alone]);
        $shared1 = $this->shipment();
        $shared2 = $this->shipment();
        $shared = $this->pickup('SHARED1', [$shared1, $shared2]);

        $this->postJson("/api/shipments/{$alone->id}/void")->assertOk()->assertJsonPath('pickup_notice', 'ยกเลิก Pickup SOLO1 กับ DHL แล้ว');
        $this->assertSame('cancelled', $solo->fresh()->status);

        $notice = $this->postJson("/api/shipments/{$shared1->id}/void")->assertOk()->json('pickup_notice');
        $this->assertStringContainsString($shared2->tracking_number, $notice);
        $this->assertSame('requested', $shared->fresh()->status);
        $this->assertSame(['SOLO1'], $this->cancelledPickups);
    }

    public function test_staff_record_that_dhl_was_notified(): void
    {
        $s = $this->shipment();
        $this->postJson("/api/shipments/{$s->id}/void")->assertOk()->assertJsonPath('carrier_cancel_requested_at', null);

        $res = $this->postJson("/api/shipments/{$s->id}/carrier-cancel-notified", ['notified_to' => 'คุณเอ DHL (โทร)'])->assertOk();
        $res->assertJsonPath('carrier_cancel_status', 'pending')->assertJsonPath('carrier_cancel_requested_to', 'คุณเอ DHL (โทร)');
        $this->assertNotNull($res->json('carrier_cancel_requested_at'));

        // Only while waiting for DHL.
        $booked = $this->shipment();
        $this->postJson("/api/shipments/{$booked->id}/carrier-cancel-notified")->assertStatus(422);
    }

    public function test_pending_cancellation_filter_and_status_normalisation(): void
    {
        $pending = $this->shipment();
        $this->postJson("/api/shipments/{$pending->id}/void")->assertOk();
        $this->shipment(); // still booked

        $ids = collect($this->getJson('/api/shipments?cancel=pending')->json('data'))->pluck('id')->all();
        $this->assertSame([$pending->id], $ids);

        $pending->fresh()->update(['status' => ' Booked ']);
        $this->assertSame('booked', DB::table('shipments')->where('id', $pending->id)->value('status'));
    }

    public function test_shipment_with_an_issued_receipt_cannot_be_voided_until_the_receipt_is(): void
    {
        $s = $this->shipment();
        $branchId = DB::table('branches')->insertGetId(['name' => 'B', 'code' => 'B1', 'created_at' => now(), 'updated_at' => now()]);
        $receiptId = DB::table('receipts')->insertGetId([
            'type' => 'CASH_RECEIPT', 'branch_id' => $branchId, 'vol_no' => 'V1', 'no' => '0001', 'issued_date' => now()->toDateString(),
            'buyer_name' => 'X', 'grand_total' => 100, 'status' => 'ISSUED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('receipt_shipment')->insert(['receipt_id' => $receiptId, 'shipment_id' => $s->id]);

        $this->postJson("/api/shipments/{$s->id}/void")->assertStatus(422)->assertJsonFragment(['error' => 'Shipment นี้มีใบเสร็จ/ใบกำกับภาษีที่ออกแล้ว (V1/0001) — กรุณา Void เอกสารนั้นก่อนที่หน้า Receipts & Tax Invoices แล้วจึง Void Shipment']);
        $this->assertSame('booked', $s->fresh()->status);

        DB::table('receipts')->where('id', $receiptId)->update(['status' => 'VOIDED']);
        $this->postJson("/api/shipments/{$s->id}/void")->assertOk()->assertJsonPath('status', 'voided');
    }

    public function test_confirm_and_unvoid_rules(): void
    {
        $s = $this->shipment();
        $this->postJson("/api/shipments/{$s->id}/void")->assertOk();

        // Undo while DHL hasn't been told.
        $this->postJson("/api/shipments/{$s->id}/unvoid")->assertOk()->assertJsonPath('status', 'booked')->assertJsonPath('carrier_cancel_status', null);

        $this->postJson("/api/shipments/{$s->id}/void")->assertOk();
        $this->postJson("/api/shipments/{$s->id}/confirm-carrier-cancel", ['reference' => 'DHL-CASE-1'])->assertOk()
            ->assertJsonPath('carrier_cancel_status', 'confirmed')->assertJsonPath('carrier_cancel_reference', 'DHL-CASE-1');

        // Once DHL confirmed, it can no longer be un-voided.
        $this->postJson("/api/shipments/{$s->id}/unvoid")->assertStatus(422);
    }
}
