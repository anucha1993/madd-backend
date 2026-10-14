<?php

namespace Tests\Unit;

use App\Support\DocumentWeightLimit;
use PHPUnit\Framework\TestCase;

class DocumentWeightLimitTest extends TestCase
{
    public function test_ups_allows_documents_up_to_5_kg_and_dhl_up_to_2_kg(): void
    {
        $docs = fn (float $weight, int $quantity = 1) => [['weight' => $weight, 'quantity' => $quantity, 'isDocument' => true]];

        $this->assertNull(DocumentWeightLimit::error('UPS', $docs(5)));
        $this->assertNotNull(DocumentWeightLimit::error('UPS', $docs(5.1)));
        $this->assertNull(DocumentWeightLimit::error('DHL', $docs(2)));
        $this->assertNotNull(DocumentWeightLimit::error('DHL', $docs(2.1)));
        $this->assertNotNull(DocumentWeightLimit::error('DHL', $docs(1.5, 2)));   // 3 kg over all pieces
    }

    public function test_boxes_are_not_limited(): void
    {
        $this->assertNull(DocumentWeightLimit::error('DHL', [['weight' => 30, 'quantity' => 1, 'isDocument' => false]]));
    }
}
