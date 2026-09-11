<?php

namespace Database\Seeders;

use App\Models\AddonCategory;
use Illuminate\Database\Seeder;

class AddonCategorySeeder extends Seeder
{
    public function run(): void
    {
        $names = ['Metal Box', 'Form / OT Fee', 'Other'];

        foreach ($names as $index => $name) {
            AddonCategory::updateOrCreate(['name' => $name], ['sort_order' => $index]);
        }
    }
}
