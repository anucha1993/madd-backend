<?php

namespace Tests\Feature;

use App\Http\Controllers\ShipmentController;
use App\Models\ApiClient;
use App\Models\Branch;
use App\Models\IntegrationSetting;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

class CriticalFixesTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $permissions, array $extra = []): User
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => ['shipment' => 'all', 'receipt' => 'all']]);
        $user = User::factory()->create($extra);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_scheduled_tracking_sync_runs_again_after_the_interval(): void
    {
        IntegrationSetting::set('tracking_sync.interval_minutes', '15');
        IntegrationSetting::set('tracking_sync.last_run_at', now()->subMinutes(20)->toDateTimeString());
        $this->artisan('shipments:sync-tracking')->assertSuccessful();
        $this->assertSame(1, DB::table('tracking_sync_logs')->count()); // ran (previously always skipped)

        IntegrationSetting::set('tracking_sync.last_run_at', now()->subMinutes(5)->toDateTimeString());
        $this->artisan('shipments:sync-tracking')->assertSuccessful();
        $this->assertSame(1, DB::table('tracking_sync_logs')->count()); // still within the interval
    }

    public function test_booking_branch_is_bound_for_single_branch_users_and_chosen_by_others(): void
    {
        $a = Branch::create(['name' => 'A', 'code' => 'A', 'status' => true]);
        $b = Branch::create(['name' => 'B', 'code' => 'B', 'status' => true]);
        $resolve = new ReflectionMethod(ShipmentController::class, 'resolveBranchId');
        $controller = app(ShipmentController::class);
        $request = fn (User $u) => tap(request(), fn ($r) => $r->setUserResolver(fn () => $u));

        $single = User::factory()->create();
        $single->branches()->sync([$a->id]);
        $this->assertSame($a->id, $resolve->invoke($controller, $request($single), $b->id)); // can't pick another branch

        $all = User::factory()->create(['can_access_all_branches' => true]);
        $this->assertSame($b->id, $resolve->invoke($controller, $request($all), $b->id));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $resolve->invoke($controller, $request($all), null); // must choose
    }

    public function test_assign_branch_to_a_shipment_without_one(): void
    {
        $a = Branch::create(['name' => 'A', 'code' => 'A', 'status' => true]);
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $shipment = Shipment::create(['agent_account_id' => $accountId, 'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'booked', 'tracking_number' => '1234567890', 'origin' => [], 'destination' => [], 'packages' => []]);

        Sanctum::actingAs($this->user(['shipment.view', 'shipment.create'], ['can_access_all_branches' => true]));
        $this->putJson("/api/shipments/{$shipment->id}/branch", ['branch_id' => $a->id])->assertOk()->assertJsonPath('branch.id', $a->id);
        $this->putJson("/api/shipments/{$shipment->id}/branch", ['branch_id' => $a->id])->assertStatus(422); // already set
    }

    public function test_original_dhl_waybill_downloads_as_attachment(): void
    {
        $this->mock(\App\Services\R2Service::class, fn ($m) => $m->shouldReceive('download')->andReturn('%PDF-dhl'));
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (array $a) => Shipment::create($a + ['agent_account_id' => $accountId, 'service_code' => 'P', 'status' => 'booked', 'tracking_number' => '5437367191', 'origin' => [], 'destination' => [], 'packages' => []]);
        $dhl = $make(['carrier' => 'DHL', 'waybill_storage_key' => 'shipments/waybill/5437367191.pdf']);
        $ups = $make(['carrier' => 'UPS', 'waybill_storage_key' => 'shipments/waybill/1Z.pdf']);

        Sanctum::actingAs($this->user(['shipment.view']));
        $res = $this->get("/api/shipments/{$dhl->id}/waybill/original")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="DHL-waybill-5437367191.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-dhl', $res->getContent());
        $this->getJson("/api/shipments/{$ups->id}/waybill/original")->assertNotFound();
    }

    public function test_voided_shipments_cannot_be_billed(): void
    {
        $a = Branch::create(['name' => 'A', 'code' => 'A', 'status' => true]);
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $voided = Shipment::create(['agent_account_id' => $accountId, 'branch_id' => $a->id, 'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'voided', 'tracking_number' => '5084355500', 'origin' => [], 'destination' => [], 'packages' => [], 'order_total' => 100]);

        Sanctum::actingAs($this->user(['receipt.create']));
        $this->postJson('/api/receipts', ['shipment_ids' => [$voided->id], 'buyer_name' => 'X', 'lines' => [['description' => 'Freight', 'amount' => 100]]])
            ->assertJsonValidationErrors('shipment_ids');
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        User::factory()->create(['username' => 'somchai', 'password' => bcrypt('right-pass')]);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/login', ['username' => 'somchai', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/login', ['username' => 'somchai', 'password' => 'right-pass'])->assertStatus(429);
    }

    public function test_markup_values_need_a_markup_setting_permission(): void
    {
        Sanctum::actingAs($this->user(['shipment.view', 'shipment.create']));
        $this->getJson('/api/markup-rules')->assertForbidden();
        $this->getJson('/api/charge-fixed-overrides')->assertForbidden();

        Sanctum::actingAs($this->user(['config.markup']));
        $this->getJson('/api/markup-rules')->assertOk();
    }

    public function test_marking_a_key_used_does_not_change_its_updated_at(): void
    {
        $client = ApiClient::create(['name' => 'Web', 'origin_city' => 'Bangkok', 'origin_postcode' => '10110', 'max_results' => 6, 'price_rounding' => 0, 'rate_limit_per_minute' => 60, 'end_user_limit_per_minute' => 10, 'status' => true]);
        $key = $client->issueKey();
        DB::table('api_clients')->where('id', $client->id)->update(['updated_at' => '2026-01-01 00:00:00']);

        $this->withToken($key)->getJson('/api/public/v1/countries')->assertOk();
        $row = DB::table('api_clients')->where('id', $client->id)->first();
        $this->assertSame('2026-01-01 00:00:00', $row->updated_at);
        $this->assertNotNull($row->last_used_at);
    }
}
