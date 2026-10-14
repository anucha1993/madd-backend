<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * {COST} (the carrier's own total) joins TOTAL / W / BOX as a formula-only Charge Code, so it shows
 * up in the formula token picker (see SpecialFormulaChargeCodeSeeder). Only inserted where missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['UPS', 'DHL'] as $provider) {
            if (DB::table('charge_codes')->where('provider', $provider)->where('code', 'COST')->exists()) {
                continue;
            }
            DB::table('charge_codes')->insert([
                'provider' => $provider,
                'code' => 'COST',
                'label' => 'ต้นทุนรวมของ Carrier (Cost)',
                'description' => 'ยอดรวมที่ Carrier คิดเรา (ก่อน Fixed Charges / Mark-up ทุกตัว) — ใช้ในสูตรเท่านั้น',
                'category' => 'SYSTEM',
                'is_custom' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('charge_codes')->where('code', 'COST')->where('category', 'SYSTEM')->delete();
    }
};
