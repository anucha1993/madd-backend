<?php

namespace Tests\Unit;

use App\Support\WholeKgBilledWeight;
use PHPUnit\Framework\TestCase;

/**
 * Rules WholeKgBilledWeight must keep for ANY mix of boxes — checked over many generated
 * shipments rather than a handful of hand-picked ones (seeded, so a failure is repeatable).
 */
class WholeKgInvariantsTest extends TestCase
{
    /** What a carrier bills: each box max(actual, L×W×H/5000) rounded up to 0.5 kg. */
    private static function carrierBilled(array $rows): float
    {
        return array_sum(array_map(function ($r) {
            $vol = ! empty($r['length']) ? $r['length'] * $r['width'] * $r['height'] / 5000 : 0;

            return ceil(round(max((float) $r['weight'], $vol) * 2, 6)) / 2 * max(1, (int) ($r['quantity'] ?? 1));
        }, $rows));
    }

    public function test_every_generated_shipment_keeps_the_rules(): void
    {
        mt_srand(20261017);
        for ($n = 0; $n < 3000; $n++) {
            $rows = [];
            foreach (range(1, mt_rand(1, 4)) as $_) {
                $doc = mt_rand(0, 9) === 0;
                $rows[] = [
                    'weight' => mt_rand(1, 7500) / 100, // 0.01 – 75 kg
                    'length' => $doc ? null : mt_rand(5, 120),
                    'width' => $doc ? null : mt_rand(5, 80),
                    'height' => $doc ? null : mt_rand(2, 80),
                    'quantity' => mt_rand(1, 3),
                    'declaredValue' => mt_rand(0, 1) ? mt_rand(100, 9000) : null,
                ];
            }
            $quoted = WholeKgBilledWeight::forQuote($rows);
            $label = json_encode($rows);
            $billed = WholeKgBilledWeight::billedWeight($rows);

            // Same number of boxes, and no box sent heavier than 70 kg unless it already was.
            $this->assertSame(array_sum(array_column($rows, 'quantity')), array_sum(array_column($quoted, 'quantity')), $label);
            $wasOver = max(array_column($rows, 'weight')) > 70;
            if (! $wasOver) {
                $this->assertLessThanOrEqual(70, max(array_column($quoted, 'weight')), $label);
            }
            // Declared value is only ever split, never lost or invented.
            $this->assertEqualsWithDelta(
                array_sum(array_map(fn ($r) => (float) ($r['declaredValue'] ?? 0), $rows)),
                array_sum(array_map(fn ($r) => (float) ($r['declaredValue'] ?? 0), $quoted)),
                0.02, $label,
            );

            $carrier = self::carrierBilled($quoted);
            if (self::carrierBilled($rows) <= WholeKgBilledWeight::FROM_KG) {
                $this->assertSame($rows, $quoted, $label); // ≤ 10 kg: sent untouched
            } else {
                $this->assertSame(0.0, fmod($billed, 1.0), $label); // over 10 kg: whole kg only
                // …and the carrier, asked with the reshaped boxes, bills exactly that whole kg.
                $this->assertSame($billed, $carrier, $label);
            }
        }
    }
}
