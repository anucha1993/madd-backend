<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Uses the DHL Express MyDHL API (same base URL / Basic Auth account credentials
 * already stored for rating), NOT the separate "Shipment Tracking - Unified" API
 * (which needs a different DHL-API-Key subscription we don't store).
 */
class DhlTrackingService
{
    public function trackByNumber(string $username, string $password, string $trackingNumber, ?string $mode = null): array
    {
        $baseUrl = rtrim($mode === 'test' ? config('services.dhl.api_url_test') : config('services.dhl.api_url'), '/');

        $response = Http::withBasicAuth($username, $password)
            ->timeout(20)
            ->get("{$baseUrl}/shipments/" . rawurlencode($trackingNumber) . '/tracking', [
                'trackingView' => 'all-checkpoints',
                'levelOfDetail' => 'all',
            ]);

        if (! $response->successful()) {
            $message = $response->json('detail')
                ?? $response->json('title')
                ?? "DHL Tracking request failed (HTTP {$response->status()})";
            throw new \RuntimeException($message);
        }

        return $this->normalize($response->json());
    }

    /**
     * DHL's exact response field names can vary slightly by account/product,
     * so every field is read defensively with fallbacks instead of assuming one shape.
     */
    private function normalize(array $raw): array
    {
        $shipments = $raw['shipments'] ?? (isset($raw['shipmentTrackingNumber']) ? [$raw] : []);
        $packages = [];

        foreach ($shipments as $shipment) {
            $events = $shipment['events'] ?? $shipment['checkpoints'] ?? [];
            $activities = array_map(function (array $event) {
                $location = $event['serviceArea'][0]['description']
                    ?? $event['location']['address']['addressLocality']
                    ?? $event['location']['description']
                    ?? null;

                return [
                    'date' => $event['date'] ?? null,
                    'time' => $event['time'] ?? null,
                    'description' => $event['description'] ?? $event['status'] ?? null,
                    'statusCode' => $event['typeCode'] ?? $event['statusCode'] ?? null,
                    'statusType' => $event['typeCode'] ?? $event['statusCode'] ?? null,
                    'location' => $location,
                ];
            }, $events);

            $currentStatus = $shipment['status']['description']
                ?? $shipment['status']['status']
                ?? $shipment['status']
                ?? ($activities[0]['description'] ?? null);

            $packages[] = [
                'trackingNumber' => $shipment['shipmentTrackingNumber'] ?? $shipment['id'] ?? null,
                'currentStatusDescription' => is_string($currentStatus) ? $currentStatus : null,
                'currentStatusCode' => $shipment['status']['statusCode'] ?? null,
                'scheduledDeliveryDate' => $shipment['estimatedTimeOfDelivery'] ?? null,
                'activities' => $activities,
                'podAvailable' => false,
                'podImageBase64' => null,
                'signatureImageBase64' => null,
            ];
        }

        return ['packages' => $packages];
    }
}
