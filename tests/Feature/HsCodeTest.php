<?php

namespace Tests\Feature;

use App\Models\HsCode;
use App\Models\Role;
use App\Models\User;
use App\Services\HsCodeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HsCodeTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $permissions): User
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_the_cpsc_list_imports_once_and_is_searchable_by_code_or_description(): void
    {
        $file = database_path('data/CPSC_HTS_List_eFiling_Jan2026.xlsx');
        $first = app(HsCodeImporter::class)->import($file, 'CPSC');
        $this->assertGreaterThan(500, $first['created']);
        $count = HsCode::count();

        $again = app(HsCodeImporter::class)->import($file, 'CPSC');
        $this->assertSame(0, $again['created']);
        $this->assertSame($count, HsCode::count());

        $atv = HsCode::where('code', '8703210110')->first();
        $this->assertSame('8703.21.0110', $atv->code_formatted);
        $this->assertSame('All-Terrain Vehicles (ATVs)', $atv->description);

        Sanctum::actingAs($this->user([]));
        $this->getJson('/api/hs-codes?lookup=1&q=8703.21')->assertOk()->assertJsonPath('0.code', '8703210110');
        $this->assertNotEmpty($this->getJson('/api/hs-codes?lookup=1&q=helmet')->json());
        $this->assertLessThanOrEqual(20, count($this->getJson('/api/hs-codes?lookup=1&q=8')->json()));
    }

    public function test_managing_the_list_needs_the_permission(): void
    {
        Sanctum::actingAs($this->user([]));
        $this->postJson('/api/hs-codes', ['code' => '6109.10', 'description' => 'T-shirts, cotton'])->assertForbidden();

        Sanctum::actingAs($this->user(['config.hs_codes']));
        $this->postJson('/api/hs-codes', ['code' => '6109.10.0012', 'description' => 'T-shirts, cotton'])
            ->assertCreated()->assertJsonPath('code', '6109100012')->assertJsonPath('code_formatted', '6109.10.0012');
        $this->postJson('/api/hs-codes', ['code' => '6109100012', 'description' => 'T-shirts, cotton'])->assertJsonValidationErrors('code');
        $this->postJson('/api/hs-codes', ['code' => '61', 'description' => 'Too short'])->assertJsonValidationErrors('code');
    }
}
