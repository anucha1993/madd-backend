<?php

namespace Database\Seeders;

use App\Models\ChargeCode;
use Illuminate\Database\Seeder;

/**
 * Seeds BILLED_WEIGHT/TOTAL as REAL ChargeCode rows (one per provider) so they show up
 * everywhere a normal Charge Code does — both the Fixed Charges "which code to override" picker
 * AND the Formula token-insertion box — instead of being a frontend-only pseudo-list
 * (superseded chargeFormulaVariables.ts approach). They never appear in any carrier's real
 * chargeBreakdown, so a Markup Rule/Fixed Override targeting them always takes the
 * "inject as a brand new line" path in ChargeMarkupService (same as any other custom code) —
 * their actual VALUES are injected into the formula evaluator's $rawAmountsByCode by
 * ChargeMarkupService::applyToResults() from the quote's billedWeight/negotiated/published/total.
 */
class SpecialFormulaChargeCodeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'BILLED_WEIGHT', 'label' => 'น้ำหนักที่คิดเงิน (Billed Weight)', 'description' => 'น้ำหนักที่ Carrier ใช้คิดค่าขนส่งจริงของใบเสนอราคานี้ (กก.) — ใช้ในสูตรเท่านั้น'],
            ['code' => 'TOTAL', 'label' => 'ยอดรวมทั้งหมด (Total)', 'description' => 'ยอดรวมค่าขนส่งทั้งหมดของใบเสนอราคานี้ ก่อนใช้กฎนี้ — ใช้ในสูตรเท่านั้น'],
        ];

        foreach (['UPS', 'DHL'] as $provider) {
            foreach ($rows as $row) {
                ChargeCode::updateOrCreate(
                    ['provider' => $provider, 'code' => $row['code']],
                    ['label' => $row['label'], 'description' => $row['description'], 'category' => 'SYSTEM', 'is_custom' => true],
                );
            }
        }
    }
}
