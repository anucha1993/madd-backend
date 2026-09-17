<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Schedules/cancels a DHL Express courier pickup. Live-verified: like UPS, a pickup is NOT tied
 * to specific tracking numbers — DHL only wants the pickup address, a planned pickup/close time,
 * and the total package count/weight being handed over.
 */
class DhlPickupService
{
    private function dhlUrl(?string $mode): string
    {
        return $mode === 'test' ? config('services.dhl.api_url_test') : config('services.dhl.api_url');
    }

    /**
     * @param array $data {
     *   accountNumber: string, contactName: string, companyName: ?string, phone: string, email: ?string,
     *   address: string, city: string, postcode: string, countryCode: string,
     *   plannedPickupDateAndTime: string (ISO8601 with offset), closeTime: string (HH:mm),
     *   totalWeightKg: float, totalPieces: int, productCode: string,
     * }
     * @return array{dispatchConfirmationNumber:?string,raw:array}
     */
    public function createPickup(array $account, array $data): array
    {
        $body = [
            'plannedPickupDateAndTime' => $data['plannedPickupDateAndTime'],
            'closeTime' => $data['closeTime'],
            'location' => 'reception',
            'locationType' => 'business',
            'accounts' => [['typeCode' => 'shipper', 'number' => $data['accountNumber']]],
            'customerDetails' => [
                'shipperDetails' => [
                    'postalAddress' => [
                        'postalCode' => $data['postcode'],
                        'cityName' => $data['city'],
                        'countryCode' => $data['countryCode'],
                        'addressLine1' => $data['address'],
                    ],
                    'contactInformation' => [
                        'phone' => $data['phone'] ?: '0000000000',
                        'companyName' => $data['companyName'] ?: $data['contactName'],
                        'fullName' => $data['contactName'] ?: ($data['companyName'] ?: 'N/A'),
                        'email' => $data['email'] ?? null,
                    ],
                ],
            ],
            'shipmentDetails' => [[
                'productCode' => $data['productCode'],
                'isCustomsDeclarable' => false,
                'unitOfMeasurement' => 'metric',
                'packages' => [['weight' => (float) $data['totalWeightKg'], 'dimensions' => ['length' => 20, 'width' => 20, 'height' => 20]]],
            ]],
        ];
        $body['customerDetails']['shipperDetails']['contactInformation'] = array_filter(
            $body['customerDetails']['shipperDetails']['contactInformation'],
            fn ($v) => $v !== null,
        );

        $response = Http::withBasicAuth($account['basic_auth_username'], $account['basic_auth_password'])
            ->withHeaders([
                'Message-Reference' => 'madd-'.now()->timestamp.'-'.bin2hex(random_bytes(4)),
                'Message-Reference-Date' => now()->toRfc7231String(),
            ])
            ->timeout(20)
            ->post($this->dhlUrl($account['mode'] ?? null).'/pickups', $body);

        $raw = $response->json();
        if (! $response->successful()) {
            throw new \RuntimeException('DHL pickup creation failed: '.($raw['detail'] ?? $raw['title'] ?? $response->status()));
        }

        return [
            'dispatchConfirmationNumber' => $raw['dispatchConfirmationNumbers'][0] ?? null,
            'raw' => $raw,
        ];
    }

    /**
     * DHL's Cancel Pickup wants requestorName/reason/accountNumber as QUERY STRING parameters —
     * live-verified that a JSON request body is silently ignored ("Required parameter
     * 'requestorName' is not present" even when sent in the body).
     */
    public function cancelPickup(array $account, string $dispatchConfirmationNumber, string $requestorName, string $reason): array
    {
        $query = http_build_query([
            'requestorName' => $requestorName,
            'reason' => $reason,
            'accountNumber' => $account['username_acc'],
        ]);

        $response = Http::withBasicAuth($account['basic_auth_username'], $account['basic_auth_password'])
            ->withHeaders([
                'Message-Reference' => 'madd-c-'.now()->timestamp.'-'.bin2hex(random_bytes(4)),
                'Message-Reference-Date' => now()->toRfc7231String(),
            ])
            ->timeout(20)
            ->delete($this->dhlUrl($account['mode'] ?? null)."/pickups/{$dispatchConfirmationNumber}?{$query}");

        $raw = $response->json();
        if (! $response->successful()) {
            throw new \RuntimeException('DHL pickup cancel failed: '.($raw['detail'] ?? $raw['message'] ?? $response->status()));
        }

        return $raw;
    }
}
