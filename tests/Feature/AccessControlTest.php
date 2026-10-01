<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Pickup;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::create(['name' => 'Branch A', 'code' => 'A']);
        $this->branchB = Branch::create(['name' => 'Branch B', 'code' => 'B']);
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $this->accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '123', 'mode' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userWithRole(string $roleKey, array $branchIds = [], bool $allBranches = false): User
    {
        $user = User::factory()->create(['can_access_all_branches' => $allBranches]);
        $user->roles()->sync(Role::where('key', $roleKey)->pluck('id'));
        $user->branches()->sync($branchIds);

        return $user;
    }

    private function shipment(Branch $branch, ?User $creator = null): Shipment
    {
        return Shipment::create([
            'agent_account_id' => $this->accountId, 'branch_id' => $branch->id, 'created_by' => $creator?->id,
            'carrier' => 'DHL', 'service_code' => 'P', 'status' => 'booked', 'tracking_number' => 'T'.$branch->code.random_int(1000, 9999),
            'origin' => ['company' => 'S'], 'destination' => ['company' => 'R'], 'packages' => [],
            'freight_amount' => 100, 'order_total' => 150, 'cost_amount' => 80, 'cost_currency' => 'THB',
            'raw_response' => ['secret' => true],
        ]);
    }

    private function pickupAttributes(): array
    {
        return [
            'agent_account_id' => $this->accountId, 'carrier' => 'DHL', 'status' => 'requested',
            'pickup_date' => now()->toDateString(), 'ready_time' => '09:00', 'close_time' => '17:00', 'address' => [],
        ];
    }

    public function test_existing_users_are_mapped_to_seeded_roles(): void
    {
        $this->assertSame(['accounting', 'admin', 'branch_manager', 'staff'], Role::orderBy('key')->pluck('key')->all());
    }

    public function test_me_returns_resolved_access(): void
    {
        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('access.is_super_admin', false)
            ->assertJsonPath('access.fields.shipment.cost', 'hidden')
            ->assertJsonPath('access.fields.shipment.pricing', 'view')
            ->assertJsonPath('access.scopes.shipment', 'branch')
            ->assertJsonFragment(['shipment.create']);
    }

    public function test_branch_scope_limits_list_and_detail(): void
    {
        $inA = $this->shipment($this->branchA);
        $inB = $this->shipment($this->branchB);
        // In Transit counts collected, not-yet-delivered shipments.
        $inA->forceFill(['picked_up_at' => now()])->save();
        $inB->forceFill(['picked_up_at' => now()])->save();
        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));

        $ids = collect($this->getJson('/api/shipments')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$inA->id], $ids);

        $this->getJson("/api/shipments/{$inA->id}")->assertOk();
        $this->getJson("/api/shipments/{$inB->id}")->assertNotFound();
        $this->getJson("/api/shipments/{$inB->id}/waybill")->assertNotFound();
        $this->getJson('/api/shipments/stats')->assertOk()->assertJsonPath('in_transit_count', 1);
    }

    public function test_branch_scope_includes_own_records_and_all_branches_flag(): void
    {
        $staff = $this->userWithRole('staff', [$this->branchA->id]);
        $ownInB = $this->shipment($this->branchB, $staff);
        Sanctum::actingAs($staff);
        $this->getJson("/api/shipments/{$ownInB->id}")->assertOk();

        $this->shipment($this->branchA);
        Sanctum::actingAs($this->userWithRole('staff', [], allBranches: true));
        $this->assertCount(2, $this->getJson('/api/shipments')->json('data'));
    }

    public function test_hidden_field_groups_are_stripped_from_json(): void
    {
        $shipment = $this->shipment($this->branchA);

        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));
        $json = $this->getJson("/api/shipments/{$shipment->id}")->assertOk()->json();
        $this->assertArrayNotHasKey('cost_amount', $json);
        $this->assertArrayNotHasKey('raw_response', $json);
        $this->assertSame('150.00', $json['order_total']);

        Sanctum::actingAs($this->userWithRole('branch_manager', [$this->branchA->id]));
        $json = $this->getJson("/api/shipments/{$shipment->id}")->assertOk()->json();
        $this->assertSame('80.00', $json['cost_amount']);
        $this->assertArrayHasKey('raw_response', $json);
    }

    public function test_action_permissions_are_enforced(): void
    {
        $shipment = $this->shipment($this->branchA);
        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));

        $this->postJson("/api/shipments/{$shipment->id}/void")->assertForbidden();
        $this->postJson('/api/markup-rules', [])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/smtp-settings')->assertForbidden();
        $this->getJson('/api/manifest-report')->assertForbidden();
        // Reference data the booking form needs stays readable.
        $this->getJson('/api/countries')->assertOk();
        $this->getJson('/api/branches')->assertOk();
    }

    public function test_user_without_any_role_gets_nothing(): void
    {
        $this->shipment($this->branchA);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/shipments')->assertForbidden();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('access.permissions', []);
    }

    public function test_super_admin_sees_everything(): void
    {
        $this->shipment($this->branchA);
        $this->shipment($this->branchB);
        Sanctum::actingAs($this->userWithRole('admin'));

        $data = $this->getJson('/api/shipments')->assertOk()->json('data');
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('cost_amount', $data[0]);
        $this->getJson('/api/users')->assertOk();
    }

    public function test_field_edit_level_is_enforced_on_booking(): void
    {
        $role = Role::create([
            'key' => 'booker', 'name' => 'Booker',
            'permissions' => ['shipment.create'],
            'field_access' => ['shipment' => ['billing' => 'view']],
            'data_scopes' => ['shipment' => 'own'],
        ]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/shipments', ['bill_transportation_to' => 'RECEIVER'])->assertForbidden();
        // Default billing values are not a change — falls through to normal validation.
        $this->postJson('/api/shipments', ['bill_transportation_to' => 'SHIPPER'])->assertUnprocessable();
    }

    public function test_body_supplied_ids_are_scoped(): void
    {
        $inB = $this->shipment($this->branchB);
        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));

        $this->postJson('/api/receipts/preview-lines', ['shipment_ids' => [$inB->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shipment_ids');
    }

    public function test_pickup_scope_follows_its_shipments_branch(): void
    {
        $inA = $this->shipment($this->branchA);
        $inB = $this->shipment($this->branchB);
        $pickupA = Pickup::forceCreate($this->pickupAttributes());
        $pickupA->shipments()->attach($inA);
        $pickupB = Pickup::forceCreate($this->pickupAttributes());
        $pickupB->shipments()->attach($inB);
        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));

        $ids = collect($this->getJson('/api/pickups')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$pickupA->id], $ids);
    }

    public function test_saved_rate_quote_hides_cost_markup_and_raw_from_staff(): void
    {
        $shipment = $this->shipment($this->branchA);
        $shipment->update(['rate_quote' => [
            'negotiated' => 6463.15, 'costNegotiated' => 6151.86, 'markupTotal' => 311.29, 'raw' => ['x' => 1], 'transitDays' => 1,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 6336.42, 'markupUnit' => 'PERCENTAGE', 'markupValue' => 3, 'markupBase' => 6151.86]],
        ]]);

        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));
        $quote = $this->getJson("/api/shipments/{$shipment->id}")->assertOk()->json('rate_quote');
        $this->assertSame(6463.15, $quote['negotiated']); // sell price stays
        $this->assertSame(1, $quote['transitDays']);
        $this->assertSame(6336.42, $quote['chargeBreakdown'][0]['amount']);
        foreach (['costNegotiated', 'markupTotal', 'raw'] as $key) {
            $this->assertArrayNotHasKey($key, $quote);
        }
        $this->assertArrayNotHasKey('markupBase', $quote['chargeBreakdown'][0]);
        $this->assertArrayNotHasKey('markupValue', $quote['chargeBreakdown'][0]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $quote = $this->getJson("/api/shipments/{$shipment->id}")->assertOk()->json('rate_quote');
        $this->assertSame(6151.86, $quote['costNegotiated']);
        $this->assertSame(6151.86, $quote['chargeBreakdown'][0]['markupBase']);
    }

    public function test_hidden_breakdown_keeps_only_carrier_insurance_lines(): void
    {
        $role = Role::create([
            'key' => 'counter', 'name' => 'Counter', 'permissions' => [],
            'field_access' => ['rate' => ['breakdown' => 'hidden', 'account' => 'hidden', 'weight' => 'hidden']],
            'data_scopes' => [],
        ]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        $quote = app(\App\Services\AccessService::class)->sanitizeRateQuote($user, [
            'negotiated' => 1439.15, 'username' => '560634572', 'zone' => '5', 'billedWeight' => 1, 'transitDays' => 1,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 762.16], ['code' => 'II', 'amount' => 120], ['code' => 'FF', 'amount' => 389.5]],
        ]);

        $this->assertSame(1439.15, $quote['negotiated']);
        $this->assertSame(1, $quote['transitDays']); // transit not hidden for this role
        $this->assertSame([['code' => 'II', 'amount' => 120]], $quote['chargeBreakdown']);
        foreach (['username', 'zone', 'billedWeight'] as $key) {
            $this->assertArrayNotHasKey($key, $quote);
        }
    }

    public function test_column_profiles_are_admin_defined_and_filtered_by_role(): void
    {
        $staffRoleId = Role::where('key', 'staff')->value('id');
        $accountingRoleId = Role::where('key', 'accounting')->value('id');

        // Only config.column_profiles holders can create.
        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));
        $this->postJson('/api/column-profiles', ['page_key' => 'shipment-list', 'name' => 'x', 'columns' => ['date']])->assertForbidden();

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->postJson('/api/column-profiles', ['page_key' => 'shipment-list', 'name' => 'หน้าร้าน', 'columns' => ['tracking_no', 'date', 'date'], 'role_ids' => [$staffRoleId]])
            ->assertCreated()->assertJsonPath('columns', ['tracking_no', 'date']);
        $this->postJson('/api/column-profiles', ['page_key' => 'shipment-list', 'name' => 'บัญชี', 'columns' => ['amount'], 'role_ids' => [$accountingRoleId]])->assertCreated();
        $this->postJson('/api/column-profiles', ['page_key' => 'shipment-list', 'name' => 'ทุกคน', 'columns' => ['status']])->assertCreated();
        $this->postJson('/api/column-profiles', ['page_key' => 'nope', 'name' => 'x', 'columns' => ['a']])->assertUnprocessable();

        $this->getJson('/api/column-profiles?page=shipment-list')->assertOk()->assertJsonPath('can_manage', true)->assertJsonCount(3, 'profiles');

        Sanctum::actingAs($this->userWithRole('staff', [$this->branchA->id]));
        $res = $this->getJson('/api/column-profiles?page=shipment-list')->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('roles', []);
        $this->assertSame(['หน้าร้าน', 'ทุกคน'], collect($res->json('profiles'))->pluck('name')->all());
        $this->assertArrayNotHasKey('role_ids', $res->json('profiles.0'));
    }

    public function test_rate_quote_vault_only_returns_quote_to_its_owner(): void
    {
        $vault = app(\App\Services\RateQuoteVault::class);
        $owner = User::factory()->create();
        $id = $vault->remember(['costNegotiated' => 100], $owner);

        $this->assertSame(['costNegotiated' => 100], $vault->recall($id, $owner));
        $this->assertNull($vault->recall($id, User::factory()->create()));
        $this->assertNull($vault->recall('nope', $owner));
    }

    public function test_role_payload_is_sanitized_against_registry(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $role = $this->postJson('/api/roles', [
            'key' => 'custom', 'name' => 'Custom',
            'permissions' => ['shipment.view', 'shipment.hack', 'nope'],
            'field_access' => ['shipment' => ['cost' => 'edit', 'billing' => 'edit']],
            'data_scopes' => ['shipment' => 'galaxy', 'receipt' => 'all'],
        ])->assertCreated()->json();

        $this->assertSame(['shipment.view'], $role['permissions']);
        $this->assertSame('view', $role['field_access']['shipment']['cost']); // no inputs -> capped at view
        $this->assertSame('edit', $role['field_access']['shipment']['billing']);
        $this->assertSame('own', $role['data_scopes']['shipment']);
        $this->assertSame('all', $role['data_scopes']['receipt']);
    }

    public function test_non_super_admin_cannot_escalate(): void
    {
        $manager = Role::create([
            'key' => 'role_admin', 'name' => 'Role admin',
            'permissions' => ['user.roles', 'user.view', 'user.create', 'user.edit', 'user.delete'], 'field_access' => [], 'data_scopes' => [],
        ]);
        $user = User::factory()->create();
        $user->roles()->sync([$manager->id]);
        Sanctum::actingAs($user);

        $this->putJson("/api/roles/{$manager->id}", ['is_super_admin' => true])->assertOk()->assertJsonPath('is_super_admin', false);

        $adminRoleId = Role::where('key', 'admin')->value('id');
        $this->putJson("/api/users/{$user->id}", ['role_ids' => [$adminRoleId]])->assertUnprocessable();
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $staffRoleId = Role::where('key', 'staff')->value('id');
        $this->putJson("/api/users/{$admin->id}", ['role_ids' => [$staffRoleId]])->assertUnprocessable();
        // Last super-admin role and roles still assigned to someone can't be deleted; others can.
        $this->deleteJson('/api/roles/'.Role::where('key', 'admin')->value('id'))->assertStatus(422);
        $this->userWithRole('staff');
        $this->deleteJson('/api/roles/'.$staffRoleId)->assertStatus(422);
        $this->deleteJson('/api/roles/'.Role::where('key', 'accounting')->value('id'))->assertNoContent();
    }
}
