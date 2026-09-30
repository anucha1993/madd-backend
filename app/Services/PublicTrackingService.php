<?php

namespace App\Services;

use App\Models\AgentAccount;
use App\Models\Shipment;
use Carbon\CarbonImmutable;

/**
 * Tracking for the Public API: shipments booked in MADD, plus — only for API keys with
 * `track_any_number` — other UPS / DHL numbers via one of our carrier accounts (trackExternal).
 * Only status + scan events are returned — no names, addresses, phone numbers, prices or proof
 * of delivery images ever leave this class.
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
        try {
            $result = $shipment->carrier === 'UPS'
                ? $this->ups->trackByInquiry($account->client_id, $account->client_secret, $asked, [], $account->mode)
                : $this->dhl->trackByNumber($account->basic_auth_username, $account->basic_auth_password, $shipment->tracking_number, $account->mode);
        } catch (\RuntimeException $e) {
            // A freshly booked label isn't in the carrier's tracking system until its first scan.
            if ($e->getCode() !== 404) {
                throw $e;
            }
            $result = ['packages' => []];
        }
        $package = $result['packages'][0] ?? null;

        if (! $package) {
            // Label created but the carrier has no scans yet.
            return $base + $this->status('not_picked_up') + ['carrier_status' => null, 'picked_up_at' => null, 'delivered_at' => null, 'estimated_delivery' => null, 'events' => []];
        }

        return $base + $this->fromPackage($shipment->carrier, $package, (bool) $shipment->picked_up_at);
    }

    /** Carrier from the number's format: UPS "1Z" + 16, DHL Express 10 digits. */
    public function detectCarrier(string $trackingNumber): ?string
    {
        if (preg_match('/^1Z[0-9A-Z]{16}$/', $trackingNumber)) {
            return 'UPS';
        }

        return preg_match('/^\d{10}$/', $trackingNumber) ? 'DHL' : null;
    }

    /**
     * A number not booked in MADD, tracked with one of our production carrier accounts.
     * null = unknown format, no usable account, or the carrier doesn't know the number.
     */
    public function trackExternal(string $trackingNumber): ?array
    {
        $carrier = $this->detectCarrier($trackingNumber);
        $account = $carrier ? AgentAccount::whereHas('agent', fn ($q) => $q->where('agent_code', $carrier))
            ->where('status', true)->where('is_api_enabled', true)->where('mode', 'production')
            ->get()
            ->filter(fn (AgentAccount $a) => $carrier === 'UPS' ? ($a->client_id && $a->client_secret) : ($a->basic_auth_username && $a->basic_auth_password))
            // A UPS number embeds the shipper account ("1Z" + 6 chars) — prefer that account.
            ->sortByDesc(fn (AgentAccount $a) => $carrier === 'UPS' && strcasecmp((string) $a->username_acc, substr($trackingNumber, 2, 6)) === 0)
            ->first() : null;
        if (! $account) {
            return null;
        }

        try {
            $result = $carrier === 'UPS'
                ? $this->ups->trackByInquiry($account->client_id, $account->client_secret, $trackingNumber, [], $account->mode)
                : $this->dhl->trackByNumber($account->basic_auth_username, $account->basic_auth_password, $trackingNumber, $account->mode);
        } catch (\RuntimeException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                return null;
            }
            throw $e;
        }
        $package = $result['packages'][0] ?? null;
        if (! $package || empty($package['activities'])) {
            return null;
        }

        return ['tracking_number' => $trackingNumber, 'carrier' => $carrier, 'service_name' => null, 'origin_country' => null, 'destination_country' => null, 'booked_at' => null]
            + $this->fromPackage($carrier, $package, false);
    }

    private function fromPackage(string $carrier, array $package, bool $knownCollected): array
    {
        $progress = $this->classifier->classify($carrier, $package);
        $status = $progress['status'] === 'not_picked_up' && $knownCollected ? 'in_transit' : $progress['status'];
        $events = array_map(fn ($a) => [
            'date' => $a['date'] ?? null,
            'time' => $a['time'] ?? null,
            'location' => $a['location'] ?? null,
            'description' => $a['description'] ?? null,
        ], $package['activities'] ?? []);
        usort($events, fn ($a, $b) => strcmp(($b['date'] ?? '').' '.($b['time'] ?? ''), ($a['date'] ?? '').' '.($a['time'] ?? '')));

        return $this->status($status) + [
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
