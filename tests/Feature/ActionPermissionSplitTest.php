<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActionPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    private function as(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => ['shipment' => 'all']]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    public function test_split_actions_need_their_own_permission(): void
    {
        $this->as(['user.roles', 'config.smtp', 'config.tracking_sync', 'config.system_alerts', 'supply_stock.report', 'shipment.create']);
        $target = Role::create(['key' => 'x', 'name' => 'X', 'permissions' => [], 'field_access' => [], 'data_scopes' => []]);

        $this->deleteJson("/api/roles/{$target->id}")->assertForbidden();
        $this->postJson('/api/smtp-settings/test', ['to' => 'a@b.co'])->assertForbidden();
        $this->postJson('/api/tracking-sync/run-now')->assertForbidden();
        $this->postJson('/api/system-alerts/resolve-all')->assertForbidden();
        $this->getJson('/api/supply-stock/report/export')->assertForbidden();
        $this->getJson('/api/shipment-drafts')->assertForbidden();

        $this->as(['user.roles', 'user.roles_delete', 'shipment.draft']);
        $this->deleteJson("/api/roles/{$target->id}")->assertSuccessful();
        $this->getJson('/api/shipment-drafts')->assertOk();
    }

    public function test_migration_grants_new_actions_only_to_roles_that_had_the_old_one(): void
    {
        $full = Role::create(['key' => 'full', 'name' => 'F', 'permissions' => ['shipment.void', 'pickup.cancel', 'pickup.create', 'branch.create', 'tracking.view'], 'field_access' => [], 'data_scopes' => []]);
        $half = Role::create(['key' => 'half', 'name' => 'H', 'permissions' => ['pickup.cancel'], 'field_access' => [], 'data_scopes' => []]);

        (require database_path('migrations/2026_10_02_000021_split_action_permissions.php'))->up();

        $f = json_decode(DB::table('roles')->find($full->id)->permissions, true);
        foreach (['shipment.unvoid', 'shipment.carrier_cancel', 'pickup.reschedule', 'branch.carrier_accounts', 'tracking.pod'] as $p) {
            $this->assertContains($p, $f);
        }
        $h = json_decode(DB::table('roles')->find($half->id)->permissions, true);
        $this->assertSame(['pickup.cancel'], $h); // reschedule needs cancel AND create
    }

    public function test_shipment_buttons_each_have_a_permission(): void
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $s = \App\Models\Shipment::create(['agent_account_id' => $accountId, 'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'voided', 'carrier_cancel_status' => 'pending', 'tracking_number' => '1234567890', 'origin' => [], 'destination' => [], 'packages' => []]);

        $this->as(['shipment.view', 'shipment.label', 'shipment.waybill', 'pickup.confirm', 'shipment.void']);
        $this->getJson("/api/shipments/{$s->id}/labels/all")->assertForbidden();
        $this->getJson("/api/shipments/{$s->id}/waybill/original")->assertForbidden();
        $this->postJson("/api/shipments/{$s->id}/mark-picked-up")->assertForbidden();
        $this->postJson("/api/shipments/{$s->id}/carrier-cancel-notified")->assertForbidden();
        $this->postJson("/api/shipments/{$s->id}/confirm-carrier-cancel")->assertForbidden();
        $this->postJson("/api/shipments/{$s->id}/unvoid")->assertForbidden();
    }

    public function test_carrier_cancel_is_replaced_by_its_three_buttons(): void
    {
        $r = Role::create(['key' => 'c', 'name' => 'C', 'permissions' => ['shipment.view', 'shipment.label', 'shipment.carrier_cancel', 'pickup.confirm'], 'field_access' => [], 'data_scopes' => []]);
        (require database_path('migrations/2026_10_02_000022_split_shipment_button_permissions.php'))->up();
        $p = json_decode(DB::table('roles')->find($r->id)->permissions, true);
        foreach (['shipment.detail', 'shipment.label_all', 'shipment.mark_picked_up', 'shipment.cancel_copy', 'shipment.cancel_notified', 'shipment.cancel_confirmed'] as $k) {
            $this->assertContains($k, $p);
        }
        $this->assertNotContains('shipment.carrier_cancel', $p);
        $this->assertNotContains('shipment.issue_receipt', $p); // needs receipt.create too
    }
}
