<?php

namespace Tests\Feature;

use App\Http\Controllers\ShipmentController;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeBranchTest extends TestCase
{
    use RefreshDatabase;

    /** The branch a booking by this user goes under (ShipmentController::resolveBranchId). */
    private function bookingBranch(User $user, ?int $chosen): int
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $method = new \ReflectionMethod(ShipmentController::class, 'resolveBranchId');

        return $method->invoke(app(ShipmentController::class), $request, $chosen);
    }

    public function test_a_user_sees_every_branch_but_books_under_their_own(): void
    {
        $a = Branch::create(['name' => 'Banglampoo', 'code' => 'BLP', 'status' => true]);
        $b = Branch::create(['name' => 'Chinatown', 'code' => 'CHN', 'status' => true]);
        $user = User::factory()->create(['can_access_all_branches' => true, 'branch_id' => $a->id]);

        $this->assertSame($a->id, $this->bookingBranch($user, $b->id)); // picking another branch doesn't change it
        $this->assertSame($a->id, $this->bookingBranch($user, null));

        // Without an own branch: as before — several branches → must pick.
        $user->update(['branch_id' => null]);
        $this->assertSame($b->id, $this->bookingBranch($user, $b->id));
        $this->expectException(ValidationException::class);
        $this->bookingBranch($user, null);
    }

    public function test_the_own_branch_is_saved_seen_and_returned_by_me(): void
    {
        $a = Branch::create(['name' => 'Banglampoo', 'code' => 'BLP', 'status' => true]);
        $b = Branch::create(['name' => 'Chinatown', 'code' => 'CHN', 'status' => true]);
        $admin = User::factory()->create(['can_access_all_branches' => true]);
        $admin->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        $staff = User::factory()->create();
        $staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        Sanctum::actingAs($admin);

        // Sees only Chinatown, belongs to Banglampoo → Banglampoo is added to what they see.
        $this->putJson("/api/users/{$staff->id}", ['branch_id' => $a->id, 'branch_ids' => [$b->id], 'can_access_all_branches' => false])
            ->assertOk()->assertJsonPath('home_branch.code', 'BLP');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $staff->branches()->pluck('branches.id')->all());

        $this->putJson("/api/users/{$staff->id}", ['branch_id' => 999])->assertStatus(422);

        Sanctum::actingAs($staff->fresh());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('home_branch.name', 'Banglampoo');
    }
}
