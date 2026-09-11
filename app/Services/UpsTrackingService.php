<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UpsTrackingService
{
    public function __construct(private UpsRateService $upsRateService)
    {
    }

    public function trackByInquiry(string $clientId, string $clientSecret, string $inquiryNumber, array $options = [], ?string $mode = null): array
    {
        $query = array_filter([
            'returnPOD' => ($options['returnPOD'] ?? false) ? 'true' : null,
            'returnSignature' => ($options['returnSignature'] ?? false) ? 'true' : null,
            'offset' => $options['offset'] ?? null,
            'count' => $options['count'] ?? null,
        ], fn ($v) => $v !== null);

        $base = $mode === 'test' ? config('services.ups.tracking_url_test') : config('services.ups.tracking_url');
        $url = rtrim($base, '/') . '/' . rawurlencode($inquiryNumber);

        return $this->request($clientId, $clientSecret, $url, $query, $mode);
    }

    public function trackByReference(string $clientId, string $clientSecret, string $referenceNumber, array $options = [], ?string $mode = null): array
    {
        $query = array_filter([
            'returnPOD' => ($options['returnPOD'] ?? false) ? 'true' : null,
            'returnSignature' => ($options['returnSignature'] ?? false) ? 'true' : null,
            'fromPickUpDate' => $options['fromPickUpDate'] ?? null,
            'toPickUpDate' => $options['toPickUpDate'] ?? null,
        ], fn ($v) => $v !== null);

        $base = $mode === 'test' ? config('services.ups.tracking_reference_url_test') : config('services.ups.tracking_reference_url');
        $url = rtrim($base, '/') . '/' . rawurlencode($referenceNumber);

        return $this->request($clientId, $clientSecret, $url, $query, $mode);
    }

    private function request(string $clientId, string $clientSecret, string $url, array $query, ?string $mode = null): array
    {
        $token = $this->upsRateService->getAccessToken($clientId, $clientSecret, $mode);

        $response = Http::withToken($token)
            ->withHeaders([
                'transId' => (string) Str::uuid(),
                'transactionSrc' => config('services.ups.transaction_src', 'testing'),
            ])
            ->timeout(20)
            ->get($url, $query);

        if (! $response->successful()) {
            $message = $response->json('response.errors.0.message')
                ?? $response->json('errors.0.message')
                ?? "UPS Tracking request failed (HTTP {$response->status()})";
            throw new \RuntimeException($message);
        }

        return $this->normalize($response->json());
    }

    private function normalize(array $raw): array
    {
        $shipments = $raw['trackResponse']['shipment'] ?? [];
        $packages = [];

        foreach ($shipments as $shipment) {
            foreach ($shipment['package'] ?? [] as $pkg) {
                $activities = array_map(function (array $activity) {
                    $address = $activity['location']['address'] ?? [];
                    $locationParts = array_filter([
                        $address['city'] ?? null,
                        $address['stateProvince'] ?? null,
                        $address['country'] ?? null,
                    ]);

                    return [
                        'date' => $this->formatDate($activity['date'] ?? null),
                        'time' => $this->formatTime($activity['time'] ?? null),
                        'description' => $activity['status']['description'] ?? null,
                        'statusCode' => $activity['status']['code'] ?? null,
                        'statusType' => $activity['status']['type'] ?? null,
                        'location' => $locationParts ? implode(', ', $locationParts) : null,
                    ];
                }, $pkg['activity'] ?? []);

                $deliveryDateEntry = collect($pkg['deliveryDate'] ?? [])->first();

                $packages[] = [
                    'trackingNumber' => $pkg['trackingNumber'] ?? $shipment['inquiryNumber'] ?? null,
                    'currentStatusDescription' => $pkg['currentStatus']['description'] ?? null,
                    'currentStatusCode' => $pkg['currentStatus']['code'] ?? null,
                    'scheduledDeliveryDate' => $this->formatDate($deliveryDateEntry['date'] ?? null),
                    'activities' => $activities,
                    'podAvailable' => isset($pkg['deliveryInformation']),
                    'podImageBase64' => $pkg['deliveryInformation']['pod'] ?? null,
                    'signatureImageBase64' => $pkg['deliveryInformation']['signature']['image'] ?? null,
                ];
            }
        }

        return ['packages' => $packages];
    }

    private function formatDate(?string $ymd): ?string
    {
        if (! $ymd || strlen($ymd) !== 8) return $ymd;

        return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    }

    private function formatTime(?string $hms): ?string
    {
        if (! $hms || strlen($hms) !== 6) return $hms;

        return substr($hms, 0, 2) . ':' . substr($hms, 2, 2) . ':' . substr($hms, 4, 2);
    }
}
