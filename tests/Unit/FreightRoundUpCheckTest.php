<?php

namespace Tests\Unit;

use App\Http\Controllers\ShipmentController;
use Tests\TestCase;

class FreightRoundUpCheckTest extends TestCase
{
    private function check(float $freight): ?string
    {
        $quote = ['carrier' => 'DHL', 'negotiated' => 11974.59, 'chargeBreakdown' => [['code' => 'BASE', 'amount' => 11974.59]]];
        $data = ['carrier' => 'DHL', 'addon_lines' => [], 'addon_total' => 0, 'freight_amount' => $freight, 'order_total' => $freight];

        return (new \ReflectionMethod(ShipmentController::class, 'checkSellTotals'))->invoke(app(ShipmentController::class), $data, $quote);
    }

    public function test_the_form_s_rounded_up_freight_matches_the_quote(): void
    {
        $this->assertNull($this->check(11975));      // what /shipment/create sends (ceil)
        $this->assertNull($this->check(11974.59));   // the raw quote, as before
        $this->assertNotNull($this->check(11974));   // rounded down / tampered
        $this->assertNotNull($this->check(11976));
    }
}
