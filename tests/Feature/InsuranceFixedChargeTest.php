<?php

namespace Tests\Feature;

use App\Models\ChargeCode;
use App\Models\ChargeFixedOverride;
use App\Models\MarkupRule;
use App\Services\ChargeMarkupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InsuranceFixedChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fixed_charge_on_the_carriers_insurance_line_replaces_its_price_but_not_freight(): void
    {
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => '566833742', 'created_at' => now(), 'updated_at' => now()]);
        $ib = ChargeCode::updateOrCreate(['provider' => 'DHL', 'code' => 'IB'], ['label' => 'EXTENDED LIABILITY']);
        ChargeFixedOverride::create(['agent_account_id' => $account, 'charge_code_id' => $ib->id, 'override_type' => 'FIXED', 'fixed_amount' => 200, 'unit' => 'THB', 'status' => true]);
        // A Mark-up rule on the insurance line is ignored — its markup is the Add-on's own %.
        MarkupRule::create(['agent_id' => $agent, 'agent_account_id' => $account, 'charge_code_id' => $ib->id, 'rule_type' => 'SIMPLE', 'value' => 50, 'unit' => 'PERCENTAGE', 'status' => true]);

        $quote = app(ChargeMarkupService::class)->applyToResults([[
            'carrier' => 'DHL', 'accountId' => $account, 'negotiated' => 1050.0, 'billedWeight' => 1,
            'chargeBreakdown' => [['code' => 'BASE', 'amount' => 1000.0], ['code' => 'IB', 'amount' => 50.0]], 'error' => null,
        ]], [['weight' => 1, 'quantity' => 1, 'isDocument' => true]], 'SG')[0];

        $ibLine = collect($quote['chargeBreakdown'])->firstWhere('code', 'IB');
        $this->assertSame(200.0, $ibLine['amount']);             // the insurance sells at the Fixed Charge
        $this->assertSame(1200.0, $quote['negotiated']);         // freight (total − insurance) still 1,000
        $this->assertSame(1050.0, $quote['costNegotiated']);     // the real cost is kept
        $this->assertSame(50.0, collect($quote['costBreakdown'])->firstWhere('code', 'IB')['amount']);
    }
}
