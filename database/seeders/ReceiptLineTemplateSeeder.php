<?php

namespace Database\Seeders;

use App\Models\ReceiptLineTemplate;
use Illuminate\Database\Seeder;

/**
 * Starter example Templates for /billing/receipts/new's "Use Template..." picker — demonstrates
 * the formula syntax (plain manual line + a {LINE NAME} * X% formula line) so staff have a
 * working example to copy/adapt instead of starting from a blank template. Idempotent
 * (firstOrCreate by name) so re-running db:seed never duplicates these.
 */
class ReceiptLineTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'ตัวอย่าง: Freight + Service Charge 12%',
                'items' => [
                    ['description' => 'FREIGHT CHARGE', 'formula' => null, 'is_non_vat' => false],
                    ['description' => 'SERVICE CHARGE', 'formula' => '{FREIGHT CHARGE} * 12%', 'is_non_vat' => false],
                ],
            ],
            [
                'name' => 'ตัวอย่าง: Freight + Insurance + Service Charge 10%',
                'items' => [
                    ['description' => 'FREIGHT CHARGE', 'formula' => null, 'is_non_vat' => false],
                    ['description' => 'INSURANCE CHARGE', 'formula' => null, 'is_non_vat' => false],
                    ['description' => 'SERVICE CHARGE', 'formula' => '({FREIGHT CHARGE} + {INSURANCE CHARGE}) * 10%', 'is_non_vat' => false],
                    ['description' => 'DOCUMENT FEE', 'formula' => null, 'is_non_vat' => true],
                ],
            ],
        ];

        foreach ($templates as $sortOrder => $row) {
            $template = ReceiptLineTemplate::firstOrCreate(
                ['name' => $row['name']],
                ['status' => true, 'sort_order' => $sortOrder],
            );
            if ($template->wasRecentlyCreated) {
                foreach ($row['items'] as $i => $item) {
                    $template->items()->create([
                        'description' => $item['description'],
                        'formula' => $item['formula'],
                        'is_non_vat' => $item['is_non_vat'],
                        'sort_order' => $i,
                    ]);
                }
            }
        }
    }
}
