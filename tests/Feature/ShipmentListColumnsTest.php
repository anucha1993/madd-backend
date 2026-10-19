<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Country;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentListColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['can_access_all_branches' => true]);
        $user->roles()->sync(Role::where('key', 'admin')->pluck('id'));

        return $user;
    }

    public function test_list_carries_the_zone_quoted_or_from_countries(): void
    {
        $branch = Branch::create(['name' => 'A', 'code' => 'A']);
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'X', 'agent_code' => 'X', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        Country::updateOrCreate(['iso2' => 'US'], ['name' => 'United States', 'ups_zone' => '5', 'dhl_zone' => '6']);
        $make = fn (string $carrier, ?array $quote) => Shipment::create([
            'agent_account_id' => $accountId, 'branch_id' => $branch->id, 'carrier' => $carrier, 'service_code' => 'P', 'status' => 'booked',
            'origin' => [], 'destination' => ['country' => 'us'], 'packages' => [], 'freight_amount' => 1, 'order_total' => 1, 'rate_quote' => $quote,
        ]);
        $dhl = $make('DHL', ['billedWeight' => 2]);
        $ups = $make('UPS', ['zone' => '7']);

        Sanctum::actingAs($this->admin());
        $rows = collect($this->getJson('/api/shipments')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('6', $rows[$dhl->id]['zone']); // DHL quotes have none → /config/countries
        $this->assertSame('7', $rows[$ups->id]['zone']); // the carrier's own quoted zone wins
    }

    public function test_profile_header_names_reach_every_user_of_the_profile(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/column-profiles', [
            'page_key' => 'shipment-list', 'name' => 'P', 'columns' => ['weight', 'dimensions'],
            'group_of' => ['weight' => 'group-weight', 'dimensions' => 'group-weight'],
            'headers' => ['group-weight' => 'ขนาด/น้ำหนัก', 'date' => '  '],
        ])->assertCreated()->assertJsonPath('headers', ['group-weight' => 'ขนาด/น้ำหนัก'])->json('id');

        $staff = User::factory()->create();
        $staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        Sanctum::actingAs($staff);
        $this->getJson('/api/column-profiles?page=shipment-list')->assertOk()
            ->assertJsonPath('profiles.0.id', $id)
            ->assertJsonPath('profiles.0.headers.group-weight', 'ขนาด/น้ำหนัก');
    }
}
