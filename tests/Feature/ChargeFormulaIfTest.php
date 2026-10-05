<?php

namespace Tests\Feature;

use App\Models\ChargeCode;
use App\Models\ChargeFixedOverride;
use App\Models\MarkupRule;
use App\Services\ChargeMarkupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChargeFormulaIfTest extends TestCase
{
    use RefreshDatabase;

    public function test_if_formulas_apply_to_real_quotes_with_box_weights(): void
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $remote = ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => '190'], ['label' => 'REMOTE AREA']);
        $ahc = ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => '100'], ['label' => 'ADDITIONAL HANDLING']);

        // Fixed Charges: Remote 800 flat for 1–26 kg, 30 THB/kg above that.
        ChargeFixedOverride::create(['agent_account_id' => $accountId, 'charge_code_id' => $remote->id, 'override_type' => 'FORMULA', 'formula' => 'IF({190} > 0, IF({W} > 1 & {W} <= 26, 800, IF({W} > 26, 30 * {W}, {190})), 0)', 'fixed_amount' => 0, 'status' => true]);
        // Markup Rules: AHC 1200 per box heavier than 30 kg, otherwise the carrier's own amount.
        MarkupRule::create(['agent_id' => $agentId, 'agent_account_id' => $accountId, 'charge_code_id' => $ahc->id, 'rule_type' => 'FORMULA', 'formula' => 'IF(BOX_OVER(30) > 0, 1200 * BOX_OVER(30), {100})', 'value' => 0, 'status' => true]);

        $quote = fn (float $weight) => [
            'accountId' => $accountId, 'billedWeight' => $weight, 'negotiated' => 1300.0,
            'chargeBreakdown' => [
                ['code' => 'BASE', 'description' => 'Base Freight', 'amount' => 1000.0, 'currency' => 'THB'],
                ['code' => '190', 'description' => 'Remote Area', 'amount' => 150.0, 'currency' => 'THB'],
                ['code' => '100', 'description' => 'Additional Handling', 'amount' => 150.0, 'currency' => 'THB'],
            ],
        ];
        $amounts = fn (array $result) => collect($result['chargeBreakdown'])->pluck('amount', 'code')->all();

        [$light] = app(ChargeMarkupService::class)->applyToResults([$quote(20)], [['weight' => 10, 'quantity' => 2]]);
        $this->assertSame(['BASE' => 1000.0, 190 => 800.0, 100 => 150.0], $amounts($light));
        $this->assertSame(1950.0, $light['negotiated']);

        [$heavy] = app(ChargeMarkupService::class)->applyToResults([$quote(70)], [['weight' => 35, 'quantity' => 2]]);
        $this->assertSame(['BASE' => 1000.0, 190 => 2100.0, 100 => 2400.0], $amounts($heavy));
    }
}
