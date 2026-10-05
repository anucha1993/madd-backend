<?php

namespace Tests\Unit;

use App\Services\ChargeFormulaEvaluator as F;
use PHPUnit\Framework\TestCase;

class ChargeFormulaEvaluatorTest extends TestCase
{
    public function test_existing_arithmetic_formulas_still_work(): void
    {
        $this->assertEqualsWithDelta(420.0, F::evaluate('({BASE} + {434}) * 35%', ['BASE' => 1000, '434' => 200]), 0.001);
        $this->assertEqualsWithDelta(-5.0, F::evaluate('-{A} / 2', ['A' => 10]), 0.001);
    }

    /** Remote: IF({190} > 0) → 1–26 kg flat 800, over 26 kg 30 THB/kg; no 190 on the quote → 0. */
    public function test_remote_area_example(): void
    {
        $formula = 'IF({190} > 0, IF({W} > 1kg & {W} <= 26kgs, 800THB, IF({W} > 26.00kgs, 30 * {W}, {190})), 0)';

        $this->assertSame(800.0, F::evaluate($formula, ['190' => 37, 'W' => 10]));
        $this->assertSame(900.0, F::evaluate($formula, ['190' => 37, 'W' => 30]));
        $this->assertSame(37.0, F::evaluate($formula, ['190' => 37, 'W' => 0.5])); // keep carrier's own
        $this->assertSame(0.0, F::evaluate($formula, ['W' => 10])); // 190 not on this quote
    }

    /** AHC loop box: 1200 per box heavier than 30 kg. */
    public function test_box_over_counts_heavy_boxes(): void
    {
        $formula = 'IF(BOX_OVER(30) > 0, 1200 * BOX_OVER(30), {100})';
        $ctx = ['package_weights' => [35, 12, 31]];

        $this->assertSame(2400.0, F::evaluate($formula, ['100' => 500], $ctx));
        $this->assertSame(500.0, F::evaluate($formula, ['100' => 500], ['package_weights' => [10]]));
        $this->assertNull(F::validate($formula));
    }

    /** Per-box weight charge: only boxes over 30 kg, by THAT box's own weight (20 + 33 kg → 33). */
    public function test_box_kg_over_sums_only_heavy_boxes(): void
    {
        $formula = 'IF(BOX_OVER(30) > 0, 30 * BOX_KG_OVER(30), 0)';

        $this->assertSame(990.0, F::evaluate($formula, [], ['package_weights' => [20, 33]]));
        $this->assertSame(0.0, F::evaluate($formula, [], ['package_weights' => [20, 25]]));
        $this->assertSame(2040.0, F::evaluate($formula, [], ['package_weights' => [33, 35, 10]]));
        $this->assertNull(F::validate($formula));
    }

    public function test_logic_functions_and_operators(): void
    {
        $v = ['A' => 5, 'B' => 0];
        $this->assertSame(1.0, F::evaluate('AND({A} > 1, {A} < 10)', $v));
        $this->assertSame(1.0, F::evaluate('{A} > 10 OR {B} = 0', $v));
        $this->assertSame(1.0, F::evaluate('{A} <> 4 && !{B}', $v));
        $this->assertSame(7.0, F::evaluate('MAX(MIN({A}, 7), 2) + IF({A} >= 5, 2)', $v));
        $this->assertSame(13.0, F::evaluate('ROUNDUP(12.1)', $v));
    }

    public function test_only_the_chosen_branch_is_evaluated(): void
    {
        // {MISSING} in the untaken branch / division by zero there must not break the result.
        $this->assertSame(1.0, F::evaluate('IF({A} > 0, 1, {MISSING} / 0)', ['A' => 1]));
    }

    public function test_missing_code_outside_a_condition_is_still_an_error(): void
    {
        $this->expectException(\RuntimeException::class);
        F::evaluate('IF({A} > 0, {MISSING}, 0)', ['A' => 1]);
    }

    public function test_validation_catches_mistakes_in_any_branch(): void
    {
        $this->assertNull(F::validate('IF({190} > 0, 800, 0)'));
        $this->assertNotNull(F::validate('IF({190} > 0, 800 +, 0)'));
        $this->assertNotNull(F::validate('IF({190} > 0)'));
        $this->assertNotNull(F::validate('IF({190} > 0, 800, W)')); // bare word, not {W}
        $this->assertNotNull(F::validate('800'));
    }
}
