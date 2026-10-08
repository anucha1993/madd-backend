<?php

namespace App\Support;

/**
 * Maps the UI's UPS Optional Service codes onto the request nodes — shared by the Rate and Ship
 * requests so the quoted price always covers exactly what gets booked.
 *
 * Signature (verified live against UPS, TH → US): PACKAGE-level DeliveryConfirmation is US-domestic
 * only — every DCIS type was rejected with "The requested accessory option is unavailable between
 * the selected locations". International signature is SHIPMENT-level, where DCISType "1" = Signature
 * Required and "2" = Adult Signature Required (charged as code 121). There is no international
 * "Delivery Confirmation without signature", so the old DCIS1 code is ignored.
 */
class UpsOptionalServices
{
    public static function shipmentLevel(array $codes): array
    {
        $options = [];
        if (in_array('SATURDAY', $codes, true)) {
            $options['SaturdayDeliveryIndicator'] = '';
        }
        $dcisType = match (true) {
            in_array('DCIS3', $codes, true) => '2',
            in_array('DCIS2', $codes, true) => '1',
            default => null,
        };
        if ($dcisType !== null) {
            $options['DeliveryConfirmation'] = ['DCISType' => $dcisType];
        }

        return $options;
    }

    public static function packageLevel(array $codes): array
    {
        $options = [];
        if (in_array('ADDRESSEE_ONLY', $codes, true)) {
            $options['DeliverToAddresseeOnlyIndicator'] = '';
        }
        if (in_array('DIRECT_ONLY', $codes, true)) {
            $options['DirectDeliveryOnlyIndicator'] = '';
        }

        return $options;
    }
}
