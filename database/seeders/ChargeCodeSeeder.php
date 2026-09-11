<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ChargeCodeSeeder extends Seeder
{
    /**
     * Run the database seeds. Data ported from the legacy agent-service's
     * charge_code_mappings seed (UPS/DHL charge codes from the real rate APIs).
     */
    public function run(): void
    {
        $path = database_path('seeders/data/charge_codes.json');

        if (! File::exists($path)) {
            $this->command?->error("ไม่พบไฟล์ข้อมูล: {$path}");

            return;
        }

        $rows = json_decode(File::get($path), true);
        $now = now();

        foreach (array_chunk($rows, 100) as $chunk) {
            $insert = array_map(function (array $row) use ($now) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $chunk);

            DB::table('charge_codes')->upsert($insert, ['provider', 'code'], ['label', 'description', 'category', 'updated_at']);
        }
    }
}
