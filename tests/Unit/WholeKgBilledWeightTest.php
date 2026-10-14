<?php

namespace Tests\Unit;

use App\Support\PieceWeightLimit;
use App\Support\WholeKgBilledWeight;
use PHPUnit\Framework\TestCase;

class WholeKgBilledWeightTest extends TestCase
{
    /** What UPS / DHL bill for the reshaped packages: per box max(actual, L×W×H/5000) rounded up to 0.5. */
    private function billed(array $packages): float
    {
        return array_sum(array_map(function ($p) {
            $volumetric = $p['length'] * $p['width'] * $p['height'] / 5000;

            return ceil(max($p['weight'], $volumetric) * 2) / 2 * $p['quantity'];
        }, WholeKgBilledWeight::forQuote($packages)));
    }

    private function box(float $weight, int $quantity = 1, float $side = 10, ?float $declared = null): array
    {
        return ['weight' => $weight, 'length' => $side, 'width' => $side, 'height' => $side, 'quantity' => $quantity, 'declaredValue' => $declared];
    }

    public function test_up_to_10_kg_is_sent_unchanged(): void
    {
        $packages = [$this->box(9.7)];
        $this->assertSame($packages, WholeKgBilledWeight::forQuote($packages));
    }

    public function test_over_10_kg_is_quoted_at_the_next_whole_kg(): void
    {
        $this->assertSame(11.0, $this->billed([$this->box(10.1)]));
        $this->assertSame(11.0, $this->billed([$this->box(5.2, 2)]));        // 5.5 + 5.5
        $this->assertSame(12.0, $this->billed([$this->box(3.6, 3)]));        // carriers bill 4 + 4 + 4 already
        $this->assertSame(11.0, WholeKgBilledWeight::billedWeight([$this->box(10.1)]));
        $this->assertSame(10.0, WholeKgBilledWeight::billedWeight([$this->box(9.7)]));
    }

    public function test_volumetric_weight_counts(): void
    {
        $this->assertSame(13.0, $this->billed([$this->box(2, 1, 40)]));      // 40×40×40 / 5000 = 12.8 → 13
    }

    public function test_a_light_bulky_box_keeps_its_real_weight(): void
    {
        // 40 kg in an 80×60×80 box = 76.8 kg volumetric → billed 77, but UPS refuses any box over
        // 70 kg ACTUAL — so it must still be sent as 40 kg.
        $packages = WholeKgBilledWeight::forQuote([['weight' => 40, 'length' => 80, 'width' => 60, 'height' => 80, 'quantity' => 1, 'declaredValue' => null]]);
        $this->assertLessThanOrEqual(70, $packages[0]['weight']);
        $this->assertSame(77.0, $this->billed([['weight' => 40, 'length' => 80, 'width' => 60, 'height' => 80, 'quantity' => 1, 'declaredValue' => null]]));
        $this->assertSame(77.0, WholeKgBilledWeight::billedWeight([['weight' => 40, 'length' => 80, 'width' => 60, 'height' => 80, 'quantity' => 1]]));
    }

    public function test_no_box_is_pushed_over_70_kg(): void
    {
        $packages = [$this->box(69.8), $this->box(20.2)];                     // 70 + 20.5 → 91
        $quoted = WholeKgBilledWeight::forQuote($packages);
        $this->assertSame(91.0, $this->billed($packages));
        $this->assertLessThanOrEqual(70, max(array_column($quoted, 'weight')));
    }

    public function test_a_box_already_over_70_kg_gets_heavier_not_bigger(): void
    {
        $quoted = WholeKgBilledWeight::forQuote([$this->box(72.3, 1, 30)]);  // 72.5 → 73
        $this->assertSame(73.0, $quoted[0]['weight']);
        $this->assertEquals(30, $quoted[0]['length']);
    }

    public function test_a_bulky_box_near_70_kg_grows_a_little_instead_of_passing_70_kg(): void
    {
        // 50 kg, 70.2 kg volumetric (→ 70.5): +0.5 kg as weight would be 71 kg actual — grow the box instead.
        $box = ['weight' => 50, 'length' => 65, 'width' => 60, 'height' => 90, 'quantity' => 1, 'declaredValue' => null];
        $quoted = WholeKgBilledWeight::forQuote([$box]);
        $this->assertSame(50.0, (float) $quoted[0]['weight']);
        $this->assertSame(71.0, $this->billed([$box]));
        $this->assertSame(71.0, ceil($quoted[0]['length'] * $quoted[0]['width'] * $quoted[0]['height'] / 5000 * 2) / 2); // whole-cm sides (UPS rounds them up)
        $this->assertSame((float) ceil($quoted[0]['length']), (float) $quoted[0]['length']);
    }

    public function test_a_row_of_several_boxes_is_split_to_add_the_half_kg_keeping_declared_value(): void
    {
        $packages = WholeKgBilledWeight::forQuote([$this->box(3.5, 3, 10, 300)]); // 10.5 → 11
        $this->assertSame(11.0, array_sum(array_map(fn ($p) => $p['weight'] * $p['quantity'], $packages)));
        $this->assertSame(300.0, array_sum(array_column($packages, 'declaredValue')));
        $this->assertSame(3, array_sum(array_column($packages, 'quantity')));
    }

    public function test_ups_boxes_over_70_kg_get_a_thai_message(): void
    {
        $this->assertNull(PieceWeightLimit::error('UPS', [$this->box(70)]));
        $this->assertStringContainsString('70', PieceWeightLimit::error('UPS', [$this->box(20), $this->box(71)]));
        $this->assertNull(PieceWeightLimit::error('DHL', [$this->box(71)]));
    }
}
