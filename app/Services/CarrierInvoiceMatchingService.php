<?php

namespace App\Services;

use App\Models\CarrierInvoiceLine;
use App\Models\Shipment;

/**
 * Matches an OCR'd invoice line back to the Shipment it belongs to, purely by tracking number
 * (UPS Shipment No. / DHL AWB number are both stored verbatim as Shipment.tracking_number at
 * booking time) — the only reliable, carrier-agnostic key available on both sides.
 */
class CarrierInvoiceMatchingService
{
    public function match(CarrierInvoiceLine $line, string $carrier): CarrierInvoiceLine
    {
        if (! $line->tracking_number) {
            return $line;
        }

        $shipment = Shipment::query()
            ->where('carrier', $carrier)
            ->where('tracking_number', $line->tracking_number)
            ->first();

        $line->shipment_id = $shipment?->id;
        $line->is_matched = (bool) $shipment;
        $line->save();

        return $line;
    }

    public function rematchAll(\App\Models\CarrierInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            $this->match($line, $invoice->carrier);
        }
    }
}
