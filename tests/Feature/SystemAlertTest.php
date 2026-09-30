<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SystemAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeats_fold_into_one_open_alert_until_resolved(): void
    {
        SystemAlert::record('tracking_sync', 'boom', ['n' => 1]);
        SystemAlert::record('tracking_sync', 'boom', ['n' => 2]);
        $alert = SystemAlert::sole();
        $this->assertSame(2, $alert->occurrences);
        $this->assertSame(['n' => 2], $alert->context);

        $alert->update(['resolved_at' => now()]);
        SystemAlert::record('tracking_sync', 'boom');
        $this->assertSame(2, SystemAlert::count()); // a new occurrence after resolving re-opens as a new row
    }

    public function test_reported_exceptions_are_recorded(): void
    {
        report(new RuntimeException('R2 upload failed'));

        $alert = SystemAlert::sole();
        $this->assertSame('exception', $alert->source);
        $this->assertSame('RuntimeException: R2 upload failed', $alert->message);
    }

    public function test_api_lists_and_resolves_alerts_for_permitted_users_only(): void
    {
        SystemAlert::record('pickup_cancel', 'a');
        SystemAlert::record('exception', 'b');

        $staff = User::factory()->create();
        Sanctum::actingAs($staff);
        $this->getJson('/api/system-alerts')->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        Sanctum::actingAs($admin);
        $this->getJson('/api/system-alerts/summary')->assertOk()->assertJson(['open' => 2]);
        $this->getJson('/api/system-alerts?source=pickup_cancel')->assertOk()->assertJsonCount(1, 'data');

        $id = SystemAlert::where('source', 'exception')->value('id');
        $this->postJson("/api/system-alerts/{$id}/resolve")->assertOk()->assertJsonPath('resolved_by.id', $admin->id);
        $this->getJson('/api/system-alerts/summary')->assertJson(['open' => 1]);
        $this->postJson('/api/system-alerts/resolve-all')->assertJson(['resolved' => 1]);
        $this->getJson('/api/system-alerts?status=resolved')->assertJsonCount(2, 'data');
    }
}
