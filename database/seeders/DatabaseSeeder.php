<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Administrator',
            'username' => 'admin',
            'email' => 'admin@madd.local',
            'password' => Hash::make('password123'),
        ]);

        $this->call(AgentSeeder::class);
        $this->call(ThaiSubdistrictSeeder::class);
        $this->call(InsuranceCountryCapSeeder::class);
        $this->call(ProductWeightBandSeeder::class);
        $this->call(ChargeCodeSeeder::class);
        $this->call(MarkupRuleSeeder::class);
        $this->call(AddonCategorySeeder::class);
        $this->call(AddonItemSeeder::class);
        $this->call(ManifestOptionSeeder::class);
    }
}
