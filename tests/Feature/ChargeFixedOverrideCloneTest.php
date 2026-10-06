<?php

namespace Tests\Feature;

use App\Models\ChargeCode;
use App\Models\ChargeFixedOverride;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargeFixedOverrideCloneTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    private function account(int $agentId, string $username): int
    {
        return DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => $username, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function agent(string $code): int
    {
        return DB::table('agents')->insertGetId(['agent_name' => $code, 'agent_code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_clones_all_overrides_skipping_codes_the_target_already_has(): void
    {
        $ups = $this->agent('UPS');
        [$source, $target] = [$this->account($ups, 'SRC'), $this->account($ups, 'DST')];
        $base = ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => 'BASE'], ['label' => 'FREIGHT']);
        $fuel = ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => '375'], ['label' => 'FUEL']);

        ChargeFixedOverride::create(['agent_account_id' => $source, 'charge_code_id' => $base->id, 'override_type' => 'FORMULA', 'formula' => '{BASE} * 90%', 'unit' => 'THB', 'status' => true]);
        ChargeFixedOverride::create(['agent_account_id' => $source, 'charge_code_id' => $fuel->id, 'override_type' => 'FIXED', 'fixed_amount' => 100, 'unit' => 'THB', 'status' => true]);
        ChargeFixedOverride::create(['agent_account_id' => $target, 'charge_code_id' => $fuel->id, 'override_type' => 'FIXED', 'fixed_amount' => 5, 'unit' => 'THB', 'status' => true]);

        $this->actingWith(['config.markup']);
        $this->postJson('/api/charge-fixed-overrides/clone', ['source_agent_account_id' => $source, 'target_agent_account_ids' => [$target]])
            ->assertOk()->assertJson(['created' => 1, 'updated' => 0, 'skipped' => 1]);

        $this->assertSame('{BASE} * 90%', ChargeFixedOverride::where('agent_account_id', $target)->where('charge_code_id', $base->id)->value('formula'));
        $this->assertEquals(5, ChargeFixedOverride::where('agent_account_id', $target)->where('charge_code_id', $fuel->id)->value('fixed_amount'));

        $this->postJson('/api/charge-fixed-overrides/clone', ['source_agent_account_id' => $source, 'target_agent_account_ids' => [$target], 'overwrite' => true])
            ->assertOk()->assertJson(['created' => 0, 'updated' => 2, 'skipped' => 0]);
        $this->assertEquals(100, ChargeFixedOverride::where('agent_account_id', $target)->where('charge_code_id', $fuel->id)->value('fixed_amount'));
    }

    public function test_refuses_other_carriers_and_needs_markup_permission(): void
    {
        $source = $this->account($this->agent('UPS'), 'SRC');
        $dhlAccount = $this->account($this->agent('DHL'), 'DHL1');

        $this->actingWith(['config.agent_accounts']);
        $this->postJson('/api/charge-fixed-overrides/clone', ['source_agent_account_id' => $source, 'target_agent_account_ids' => [$dhlAccount]])->assertForbidden();

        $this->actingWith(['config.markup']);
        $this->postJson('/api/charge-fixed-overrides/clone', ['source_agent_account_id' => $source, 'target_agent_account_ids' => [$dhlAccount]])->assertStatus(422);
    }
}
