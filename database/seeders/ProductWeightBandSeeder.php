<?php

namespace Database\Seeders;

use App\Models\ProductWeightBand;
use Illuminate\Database\Seeder;

class ProductWeightBandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $bands = [
            ['code' => 'CPM10', 'label' => 'CPM10', 'package_type' => 'box', 'min_weight' => null, 'max_weight' => null],
            ['code' => 'CPM25', 'label' => 'CPM25', 'package_type' => 'box', 'min_weight' => null, 'max_weight' => null],
            ['code' => 'DOCUMENT', 'label' => 'Document', 'package_type' => 'document', 'min_weight' => null, 'max_weight' => null],
            ['code' => 'REG_0.1-5.0KG', 'label' => 'REG 0.1-5.0KG', 'package_type' => 'box', 'min_weight' => 0.1, 'max_weight' => 5.0],
            ['code' => 'REG_5.1-20KG', 'label' => 'REG 5.1-20KG', 'package_type' => 'box', 'min_weight' => 5.1, 'max_weight' => 20],
            ['code' => 'REG_20.1-44KG', 'label' => 'REG 20.1-44KG', 'package_type' => 'box', 'min_weight' => 20.1, 'max_weight' => 44],
            ['code' => 'REG_44.1-70KG', 'label' => 'REG 44.1-70KG', 'package_type' => 'box', 'min_weight' => 44.1, 'max_weight' => 70],
            ['code' => 'REG_70.1-99KG', 'label' => 'REG 70.1-99KG', 'package_type' => 'box', 'min_weight' => 70.1, 'max_weight' => 99],
            ['code' => 'REG_99.1-299KG', 'label' => 'REG 99.1-299KG', 'package_type' => 'box', 'min_weight' => 99.1, 'max_weight' => 299],
            ['code' => 'REG_299.1-499KG', 'label' => 'REG 299.1-499KG', 'package_type' => 'box', 'min_weight' => 299.1, 'max_weight' => 499],
            ['code' => 'REG_499.1-999KG', 'label' => 'REG 499.1-999KG', 'package_type' => 'box', 'min_weight' => 499.1, 'max_weight' => 999],
            ['code' => 'REG_999.1KG', 'label' => 'REG 999.1KG+', 'package_type' => 'box', 'min_weight' => 999.1, 'max_weight' => null],
            ['code' => 'FC', 'label' => 'F/C', 'package_type' => 'box', 'min_weight' => null, 'max_weight' => null],
        ];

        foreach ($bands as $i => $band) {
            ProductWeightBand::firstOrCreate(
                ['code' => $band['code']],
                $band + ['sort_order' => $i, 'status' => true],
            );
        }
    }
}
