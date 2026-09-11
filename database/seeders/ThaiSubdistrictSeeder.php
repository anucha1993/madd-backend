<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ThaiSubdistrictSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('seeders/data/thai_subdistricts.json');

        if (! File::exists($path)) {
            $this->command?->error("ไม่พบไฟล์ข้อมูล: {$path}");

            return;
        }

        $rows = json_decode(File::get($path), true);
        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            $insert = array_map(function (array $row) use ($now) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $chunk);

            DB::table('thai_subdistricts')->upsert($insert, ['tambon_id'], [
                'name_th', 'name_en', 'district_id', 'district_name_th', 'district_name_en',
                'province_id', 'province_name_th', 'province_name_en', 'region',
                'zip_code', 'zip_code_all', 'updated_at',
            ]);
        }

        $this->command?->info('นำเข้าข้อมูลตำบล/แขวง ' . count($rows) . ' รายการเรียบร้อย');
    }
}
