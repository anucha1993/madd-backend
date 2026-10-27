<?php

namespace Tests\Unit;

use App\Http\Controllers\ShipmentController;
use PHPUnit\Framework\TestCase;

class PaymentMethodsTest extends TestCase
{
    public function test_several_payment_methods_are_stored_comma_separated(): void
    {
        $this->assertSame('QR,CARD', ShipmentController::paymentMethods(' QR, CARD ,QR,'));
        $this->assertSame('DAY', ShipmentController::paymentMethods('DAY'));
        $this->assertNull(ShipmentController::paymentMethods(''));
        $this->assertNull(ShipmentController::paymentMethods(null));
    }
}
