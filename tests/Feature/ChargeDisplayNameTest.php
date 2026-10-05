<?php

namespace Tests\Feature;

use App\Models\ChargeCode;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargeDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): User
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => ['shipment' => 'all', 'receipt' => 'all']]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function code(string $provider, string $code, string $label): ChargeCode
    {
        return ChargeCode::updateOrCreate(['provider' => $provider, 'code' => $code], ['label' => $label]);
    }

    public function test_display_names_need_their_own_permission_to_change_but_anyone_can_read_them(): void
    {
        $base = $this->code('UPS', 'BASE', 'FREIGHT');

        $this->actingWith(['shipment.create']);
        $this->putJson("/api/charge-codes/{$base->id}/display-name", ['display_name' => 'ค่าขนส่ง'])->assertForbidden();
        $this->getJson('/api/charge-codes')->assertForbidden();

        $this->actingWith(['config.charge_names']);
        $this->getJson('/api/charge-codes')->assertOk();
        $this->getJson('/api/markup-rules')->assertForbidden(); // markup values stay with config.markup
        $this->putJson("/api/charge-codes/{$base->id}/display-name", ['display_name' => '  ค่าขนส่ง  '])->assertOk()->assertJsonPath('display_name', 'ค่าขนส่ง');

        $this->actingWith(['shipment.create']);
        $this->getJson('/api/charge-display-names')->assertOk()->assertExactJson([['provider' => 'UPS', 'code' => 'BASE', 'display_name' => 'ค่าขนส่ง', 'display_rule' => null]]);
    }

    public function test_clearing_a_display_name_falls_back_to_the_carrier_description(): void
    {
        $base = $this->code('UPS', 'BASE', 'FREIGHT');
        $base->update(['display_name' => 'ค่าขนส่ง']);

        $this->actingWith(['config.charge_names']);
        $this->putJson("/api/charge-codes/{$base->id}/display-name", ['display_name' => ''])->assertOk()->assertJsonPath('display_name', null);
        $this->assertSame([], ChargeCode::displayNameMap());
    }

    public function test_a_missing_carrier_code_can_be_added_with_its_display_name(): void
    {
        $this->actingWith(['config.charge_names']);
        $this->postJson('/api/charge-display-names', ['provider' => 'DHL', 'code' => 'YK', 'label' => '12:00 Premium', 'display_name' => 'ส่งก่อนเที่ยง'])
            ->assertCreated()->assertJsonPath('is_custom', false);
        $this->postJson('/api/charge-display-names', ['provider' => 'DHL', 'code' => 'YK', 'display_name' => 'x'])->assertStatus(422);
    }

    public function test_suggested_receipt_lines_use_display_names(): void
    {
        $this->code('UPS', 'BASE', 'FREIGHT')->update(['display_name' => 'ค่าขนส่งระหว่างประเทศ']);
        $this->code('UPS', '375', 'FUEL SURCHARGE'); // no display name — keeps the carrier's own

        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $shipment = Shipment::create([
            'agent_account_id' => $accountId, 'carrier' => 'UPS', 'service_code' => '65', 'status' => 'booked',
            'tracking_number' => '1Z0001', 'origin' => [], 'destination' => [], 'packages' => [], 'order_total' => 762,
            'rate_quote' => ['chargeBreakdown' => [
                ['code' => 'BASE', 'description' => 'Base Freight', 'amount' => 500, 'currency' => 'THB'],
                ['code' => '375', 'description' => 'Fuel Surcharge', 'amount' => 262, 'currency' => 'THB'],
            ]],
        ]);

        $this->actingWith(['receipt.create']);
        $descriptions = collect($this->postJson('/api/receipts/preview-lines', ['shipment_ids' => [$shipment->id]])->assertOk()->json('lines'))->pluck('description')->all();

        $this->assertSame(['ค่าขนส่งระหว่างประเทศ (UPS)', 'FUEL SURCHARGE (UPS)'], $descriptions);
        // The stored carrier description is never rewritten — reports keyword-match on it.
        $this->assertSame('Base Freight', $shipment->fresh()->rate_quote['chargeBreakdown'][0]['description']);
    }

    public function test_conditional_rule_picks_a_name_from_another_codes_amount_on_the_same_quote(): void
    {
        $surge = $this->code('UPS', '434', 'SURGE FEE COMMERCIAL');

        $this->actingWith(['config.charge_names']);
        $this->putJson("/api/charge-codes/{$surge->id}/display-name", [
            'display_name' => 'AAAA',
            'display_rule' => ['code' => '190', 'op' => '>', 'value' => 0, 'name' => 'BBBBB'],
        ])->assertOk()->assertJsonPath('display_rule.name', 'BBBBB');
        $this->putJson("/api/charge-codes/{$surge->id}/display-name", ['display_name' => 'AAAA', 'display_rule' => ['code' => '190', 'op' => '~', 'name' => 'x']])->assertStatus(422);

        $map = ChargeCode::displayNameMap();
        $name = fn (array $lines) => ChargeCode::displayNameFor($map, 'UPS', '434', 'Surge Fee Commercial', ChargeCode::amountsByCode($lines));
        $this->assertSame('BBBBB', $name([['code' => '434', 'amount' => 37], ['code' => '190', 'amount' => 37]]));
        $this->assertSame('AAAA', $name([['code' => '434', 'amount' => 37]])); // 190 missing = 0
        $this->assertSame('AAAA', $name([['code' => '434', 'amount' => 37], ['code' => '190', 'amount' => 0]]));

        // Saving only display_name leaves the rule as-is; sending null clears it.
        $this->putJson("/api/charge-codes/{$surge->id}/display-name", ['display_name' => 'AAAA'])->assertJsonPath('display_rule.code', '190');
        $this->putJson("/api/charge-codes/{$surge->id}/display-name", ['display_name' => 'AAAA', 'display_rule' => null])->assertJsonPath('display_rule', null);
    }
}
