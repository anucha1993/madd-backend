<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class InsuranceCountryCapSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('seeders/data/insurance_country_caps.json');

        if (! File::exists($path)) {
            $this->command?->error("ไม่พบไฟล์ข้อมูล: {$path}");

            return;
        }

        $rows = json_decode(File::get($path), true);
        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            $insert = array_map(function (array $row) use ($now) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $chunk);

            DB::table('insurance_country_caps')->insert($insert);
        }
    }
}
