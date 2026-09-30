<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Turns one normalized tracking package (UpsTrackingService / DhlTrackingService ->
 * packages[0]) into our 3-state tracking_status plus WHEN the courier actually collected it —
 * decided from the carriers' own status/event CODES rather than description wording, so a
 * pre-pickup event ("Shipment information received", label created) is never mistaken for a
 * pickup.
 *
 * - DHL: shipment status.statusCode 'pre-transit' | 'transit' | 'delivered'; event typeCode
 *   'PU' = picked up, 'OK' = delivered.
 * - UPS: activity status.type 'M' = manifest only (label created), 'P' = pickup, 'I' = in
 *   transit, 'D' = delivered ('X' exceptions alone never count as collected).
 *
 * Carrier event date/time are local to the scan location — origin scans are in Thailand, so
 * they're read as Asia/Bangkok.
 */
class TrackingStatusClassifier
{
    private const LOCAL_TZ = 'Asia/Bangkok';

    private const UPS_COLLECTED_TYPES = ['P', 'I', 'D', 'O'];

    /**
     * @return array{status:string, picked_up_at:?CarbonImmutable, delivered_at:?CarbonImmutable}
     */
    public function classify(string $carrier, array $package): array
    {
        $activities = $package['activities'] ?? [];
        $code = strtolower((string) ($package['currentStatusCode'] ?? ''));
        $description = strtolower((string) ($package['currentStatusDescription'] ?? ''));

        if ($carrier === 'DHL') {
            $pickupEvent = $this->earliest(array_filter($activities, fn ($a) => strtoupper((string) ($a['statusCode'] ?? '')) === 'PU'));
            $deliveredEvent = $this->latest(array_filter($activities, fn ($a) => strtoupper((string) ($a['statusCode'] ?? '')) === 'OK'));
            $delivered = $code === 'delivered' || $deliveredEvent !== null;
            $collected = $delivered || $code === 'transit' || $pickupEvent !== null;
            // In transit without an explicit PU event (some products skip it) — the first scan
            // is the best available proof of collection.
            $collectedAt = $pickupEvent ?? ($collected ? $this->earliest($activities) : null);
        } else {
            $types = fn ($a) => strtoupper((string) ($a['statusType'] ?? ''));
            $collectedEvents = array_filter($activities, fn ($a) => in_array($types($a), self::UPS_COLLECTED_TYPES, true));
            $deliveredEvent = $this->latest(array_filter($activities, fn ($a) => $types($a) === 'D'));
            $delivered = $deliveredEvent !== null || str_contains($description, 'delivered');
            $collectedAt = $this->earliest($collectedEvents);
            $collected = $delivered || $collectedAt !== null;
        }

        return [
            'status' => $delivered ? 'delivered' : ($collected ? 'in_transit' : 'not_picked_up'),
            'picked_up_at' => $collected ? $this->timeOf($collectedAt ?? $deliveredEvent) : null,
            'delivered_at' => $delivered ? $this->timeOf($deliveredEvent) : null,
        ];
    }

    private function earliest(array $activities): ?array
    {
        $sorted = $this->sorted($activities);

        return $sorted[0] ?? null;
    }

    private function latest(array $activities): ?array
    {
        $sorted = $this->sorted($activities);

        return $sorted ? end($sorted) : null;
    }

    private function sorted(array $activities): array
    {
        $withTime = array_values(array_filter($activities, fn ($a) => $this->timeOf($a) !== null));
        usort($withTime, fn ($a, $b) => $this->timeOf($a) <=> $this->timeOf($b));

        return $withTime;
    }

    private function timeOf(?array $activity): ?CarbonImmutable
    {
        if (! $activity || empty($activity['date'])) {
            return null;
        }
        try {
            return CarbonImmutable::parse(trim($activity['date'].' '.($activity['time'] ?? '00:00:00')), self::LOCAL_TZ)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
