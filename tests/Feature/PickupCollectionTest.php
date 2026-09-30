<?php

namespace Tests\Feature;

use App\Console\Commands\NotifyOverduePickups;
use App\Mail\ScheduledReportMail;
use App\Models\Branch;
use App\Models\IntegrationSetting;
use App\Services\SmtpSettingService;
use Illuminate\Support\Facades\Mail;
use App\Models\Pickup;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\DhlTrackingService;
use App\Services\TrackingStatusClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * On-call pickups aren't linked to tracking numbers by the carrier — collection is proven per
 * shipment by tracking scans (TrackingStatusClassifier) or staff confirmation.
 */
class PickupCollectionTest extends TestCase
{
    use RefreshDatabase;

    private int $accountId;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Branch A', 'code' => 'PCA']);
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHLPC', 'created_at' => now(), 'updated_at' => now()]);
        $this->accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '123', 'basic_auth_username' => 'u', 'basic_auth_password' => 'p', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        $user->branches()->sync([$this->branch->id]);

        return $user;
    }

    private function shipment(array $extra = []): Shipment
    {
        return Shipment::create($extra + [
            'agent_account_id' => $this->accountId, 'branch_id' => $this->branch->id, 'carrier' => 'DHL', 'service_code' => 'P',
            'status' => 'booked', 'tracking_number' => (string) random_int(1000000000, 9999999999),
            'origin' => [], 'destination' => [], 'packages' => [['weight' => 1, 'quantity' => 1]],
        ]);
    }

    private function pickup(array $shipments, array $extra = []): Pickup
    {
        $pickup = Pickup::forceCreate($extra + [
            'agent_account_id' => $this->accountId, 'carrier' => 'DHL', 'status' => 'requested', 'carrier_reference' => 'PRG1',
            'pickup_date' => now('Asia/Bangkok')->addDay()->toDateString(), 'ready_time' => '09:00', 'close_time' => '17:00', 'address' => [],
        ]);
        $pickup->shipments()->attach(collect($shipments)->pluck('id'));

        return $pickup;
    }

    public function test_classifier_uses_carrier_codes_not_wording(): void
    {
        $c = new TrackingStatusClassifier;

        // DHL pre-pickup: shipment info received is NOT a pickup.
        $r = $c->classify('DHL', ['currentStatusCode' => 'pre-transit', 'currentStatusDescription' => 'Shipment information received',
            'activities' => [['date' => '2026-09-30', 'time' => '09:00:00', 'statusCode' => 'SA']]]);
        $this->assertSame('not_picked_up', $r['status']);
        $this->assertNull($r['picked_up_at']);

        // DHL PU event -> picked up at that scan time (Bangkok -> UTC).
        $r = $c->classify('DHL', ['currentStatusCode' => 'transit', 'activities' => [
            ['date' => '2026-09-30', 'time' => '15:10:00', 'statusCode' => 'PL'],
            ['date' => '2026-09-30', 'time' => '14:05:00', 'statusCode' => 'PU'],
        ]]);
        $this->assertSame('in_transit', $r['status']);
        $this->assertSame('2026-09-30 07:05:00', $r['picked_up_at']->format('Y-m-d H:i:s'));

        // DHL delivered.
        $r = $c->classify('DHL', ['currentStatusCode' => 'delivered', 'activities' => [
            ['date' => '2026-09-30', 'time' => '14:05:00', 'statusCode' => 'PU'],
            ['date' => '2026-10-02', 'time' => '11:00:00', 'statusCode' => 'OK'],
        ]]);
        $this->assertSame('delivered', $r['status']);
        $this->assertSame('2026-10-02 04:00:00', $r['delivered_at']->format('Y-m-d H:i:s'));

        // UPS label created only (M) -> not picked up; P scan -> picked up.
        $this->assertSame('not_picked_up', $c->classify('UPS', ['currentStatusDescription' => 'Shipper created a label', 'activities' => [
            ['date' => '2026-09-30', 'time' => '08:00:00', 'statusType' => 'M'],
        ]])['status']);
        $r = $c->classify('UPS', ['activities' => [
            ['date' => '2026-09-30', 'time' => '08:00:00', 'statusType' => 'M'],
            ['date' => '2026-09-30', 'time' => '16:30:00', 'statusType' => 'P'],
        ]]);
        $this->assertSame('in_transit', $r['status']);
        $this->assertSame('2026-09-30 09:30:00', $r['picked_up_at']->format('Y-m-d H:i:s'));
    }

    public function test_pickup_collection_progress_and_manual_confirmation(): void
    {
        $a = $this->shipment();
        $b = $this->shipment();
        $pickup = $this->pickup([$a, $b]);
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/pickups')->assertOk()->assertJsonPath('data.0.collection', ['state' => 'waiting', 'picked' => 0, 'total' => 2]);

        $this->postJson("/api/shipments/{$a->id}/mark-picked-up")->assertOk()->assertJsonPath('picked_up_source', 'manual');
        $this->getJson('/api/pickups')->assertJsonPath('data.0.collection.state', 'partial');

        $this->postJson("/api/pickups/{$pickup->id}/confirm-collected")->assertOk()->assertJsonPath('collection', ['state' => 'collected', 'picked' => 2, 'total' => 2]);
        $this->assertSame('in_transit', $b->fresh()->tracking_status);
    }

    public function test_overdue_when_close_time_passed(): void
    {
        $this->pickup([$this->shipment()], ['pickup_date' => now('Asia/Bangkok')->subDay()->toDateString()]);
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/pickups')->assertJsonPath('data.0.collection.state', 'overdue');
    }

    public function test_overdue_pickups_are_emailed_once_and_filterable(): void
    {
        Mail::fake();
        $smtp = $this->createMock(SmtpSettingService::class);
        $smtp->method('isConfigured')->willReturn(true);
        $smtp->method('isEnabled')->willReturn(true);
        $this->app->instance(SmtpSettingService::class, $smtp);
        IntegrationSetting::set(NotifyOverduePickups::RECIPIENTS_KEY, 'ops@madd.test');

        $creator = $this->staff();
        $overdue = $this->pickup([$this->shipment()], ['pickup_date' => now('Asia/Bangkok')->subDay()->toDateString(), 'created_by' => $creator->id]);
        $this->pickup([$this->shipment(['picked_up_at' => now()])], ['pickup_date' => now('Asia/Bangkok')->subDay()->toDateString()]); // collected
        $this->pickup([$this->shipment()]); // tomorrow

        $this->artisan('pickups:notify-overdue')->assertSuccessful();
        Mail::assertSent(ScheduledReportMail::class, fn ($mail) => $mail->hasTo('ops@madd.test') && $mail->hasTo($creator->email));
        Mail::assertSentCount(1);
        $this->assertNotNull($overdue->fresh()->overdue_notified_at);

        $this->artisan('pickups:notify-overdue')->assertSuccessful();
        Mail::assertSentCount(1); // not re-sent

        Sanctum::actingAs($creator);
        $this->assertSame([$overdue->id], collect($this->getJson('/api/pickups?overdue=1')->json('data'))->pluck('id')->all());
    }

    public function test_tracking_filter_groups(): void
    {
        $unscheduled = $this->shipment();
        $awaiting = $this->shipment();
        $this->pickup([$awaiting]);
        $collected = $this->shipment(['picked_up_at' => now(), 'tracking_status' => 'in_transit']);
        $delivered = $this->shipment(['picked_up_at' => now(), 'tracking_status' => 'delivered']);
        Sanctum::actingAs($this->staff());

        $ids = fn ($group) => collect($this->getJson("/api/shipments?tracking={$group}")->json('data'))->pluck('id')->all();
        $this->assertSame([$unscheduled->id], $ids('not_picked_up'));
        $this->assertSame([$awaiting->id], $ids('awaiting_pickup'));
        $this->assertSame([$collected->id], $ids('in_transit'));
        $this->assertSame([$delivered->id], $ids('delivered'));
    }

    public function test_collected_shipment_cannot_be_scheduled_again(): void
    {
        $collected = $this->shipment(['picked_up_at' => now()]);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/pickups', [
            'agent_account_id' => $this->accountId, 'shipment_ids' => [$collected->id], 'pickup_date' => now()->addDay()->toDateString(),
            'ready_time' => '09:00', 'close_time' => '17:00', 'address' => 'x', 'city' => 'Bangkok', 'postcode' => '10110',
        ])->assertStatus(422)->assertJsonFragment(['error' => 'Shipment ต่อไปนี้ Courier รับไปแล้ว ไม่ต้องนัด Pickup: '.$collected->tracking_number]);
    }

    public function test_sync_sets_scan_time_and_never_downgrades_manual_confirmation(): void
    {
        $scanned = $this->shipment();
        $manual = $this->shipment();
        $manual->markPickedUpManually(null);

        $fake = new class extends DhlTrackingService
        {
            public array $responses = [];

            public function __construct()
            {
            }

            public function trackByNumber(string $username, string $password, string $trackingNumber, ?string $mode = null): array
            {
                return ['packages' => [$this->responses[$trackingNumber]]];
            }
        };
        $fake->responses = [
            $scanned->tracking_number => ['currentStatusCode' => 'transit', 'currentStatusDescription' => 'Shipment picked up', 'activities' => [['date' => '2026-09-30', 'time' => '14:05:00', 'statusCode' => 'PU']]],
            // Carrier feed still lagging behind the staff confirmation.
            $manual->tracking_number => ['currentStatusCode' => 'pre-transit', 'currentStatusDescription' => 'Shipment information received', 'activities' => []],
        ];
        $this->app->instance(DhlTrackingService::class, $fake);

        $this->artisan('shipments:sync-tracking', ['--force' => true])->assertSuccessful();

        $scanned->refresh();
        $this->assertSame('in_transit', $scanned->tracking_status);
        $this->assertSame('carrier', $scanned->picked_up_source);
        $this->assertSame('2026-09-30 07:05:00', $scanned->picked_up_at->utc()->format('Y-m-d H:i:s'));

        $manual->refresh();
        $this->assertSame('in_transit', $manual->tracking_status);
        $this->assertSame('manual', $manual->picked_up_source);
    }
}
