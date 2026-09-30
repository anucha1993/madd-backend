<?php

namespace App\Services;

use App\Models\Shipment;
use Carbon\CarbonImmutable;

/**
 * Tracking for the Public API: only shipments booked in MADD (so the endpoint can't be used to
 * track arbitrary numbers on our carrier quota), and only status + scan events — no names,
 * addresses, phone numbers or prices ever leave this class.
 */
class PublicTrackingService
{
    private const STATUS_TEXT = [
        'not_picked_up' => 'รอ Courier เข้ารับพัสดุ',
        'in_transit' => 'อยู่ระหว่างขนส่ง',
        'delivered' => 'จัดส่งสำเร็จ',
        'cancelled' => 'ยกเลิกการจัดส่ง',
    ];

    public function __construct(
        private UpsTrackingService $ups,
        private DhlTrackingService $dhl,
        private TrackingStatusClassifier $classifier,
    ) {}

    /** Booked (non-test) shipment by its main or per-piece tracking number. */
    public function find(string $trackingNumber): ?Shipment
    {
        return Shipment::with('agentAccount')
            ->whereIn('status', ['booked', 'voided'])
            ->where(fn ($q) => $q->where('tracking_number', $trackingNumber)->orWhere('pieces', 'like', '%"'.$trackingNumber.'"%'))
            ->latest('id')
            ->get()
            ->first(fn (Shipment $s) => ! $s->is_test);
    }

    public function track(Shipment $shipment, string $asked): array
    {
        $base = [
            'tracking_number' => $asked,
            'carrier' => $shipment->carrier,
            'service_name' => $shipment->service_label,
            'origin_country' => $shipment->origin['country'] ?? null,
            'destination_country' => $shipment->destination['country'] ?? null,
            'booked_at' => $shipment->created_at?->toDateString(),
        ];

        if ($shipment->status === 'voided') {
            return $base + $this->status('cancelled') + ['picked_up_at' => null, 'delivered_at' => null, 'estimated_delivery' => null, 'events' => []];
        }

        $account = $shipment->agentAccount;
        $result = $shipment->carrier === 'UPS'
            ? $this->ups->trackByInquiry($account->client_id, $account->client_secret, $asked, [], $account->mode)
            : $this->dhl->trackByNumber($account->basic_auth_username, $account->basic_auth_password, $shipment->tracking_number, $account->mode);
        $package = $result['packages'][0] ?? null;

        if (! $package) {
            // Label created but the carrier has no scans yet.
            return $base + $this->status('not_picked_up') + ['carrier_status' => null, 'picked_up_at' => null, 'delivered_at' => null, 'estimated_delivery' => null, 'events' => []];
        }

        $progress = $this->classifier->classify($shipment->carrier, $package);
        $status = $progress['status'] === 'not_picked_up' && $shipment->picked_up_at ? 'in_transit' : $progress['status'];
        $events = array_map(fn ($a) => [
            'date' => $a['date'] ?? null,
            'time' => $a['time'] ?? null,
            'location' => $a['location'] ?? null,
            'description' => $a['description'] ?? null,
        ], $package['activities'] ?? []);
        usort($events, fn ($a, $b) => strcmp(($b['date'] ?? '').' '.($b['time'] ?? ''), ($a['date'] ?? '').' '.($a['time'] ?? '')));

        return $base + $this->status($status) + [
            'carrier_status' => $package['currentStatusDescription'] ?? null,
            'picked_up_at' => $this->iso($progress['picked_up_at'] ?? null),
            'delivered_at' => $this->iso($progress['delivered_at'] ?? null),
            'estimated_delivery' => $package['scheduledDeliveryDate'] ?? null,
            'events' => array_values($events),
        ];
    }

    private function status(string $status): array
    {
        return ['status' => $status, 'status_text' => self::STATUS_TEXT[$status]];
    }

    private function iso(?CarbonImmutable $time): ?string
    {
        return $time?->setTimezone('Asia/Bangkok')->toIso8601String();
    }
}
