<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Agent::firstOrCreate(['agent_code' => 'UPS'], ['agent_name' => 'UPS', 'status' => true]);
        Agent::firstOrCreate(['agent_code' => 'DHL'], ['agent_name' => 'DHL', 'status' => true]);
    }
}
