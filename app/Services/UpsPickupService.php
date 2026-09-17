<?php

namespace App\Services;

use App\Exceptions\UpsTokenExpiredException;
use Illuminate\Support\Facades\Http;

/**
 * Schedules/cancels an on-call UPS courier pickup — a SEPARATE API from Shipping, requiring its
 * own UPS production certification (per UPS's own Pickup Business Rules: "To be granted
 * production rights to the Pickup APIs please follow the steps in the Developer's Guides and
 * return to UPS.com to complete approval and certification").
 *
 * Live-verified: a pickup is NOT tied to specific tracking numbers at all — UPS only wants the
 * pickup address, a ready/close time window, and the TOTAL piece count/weight being handed over.
 * The courier scans whatever boxes are actually there when they arrive.
 */
class UpsPickupService
{
    private function upsUrl(string $key, ?string $mode): string
    {
        $suffix = $mode === 'test' ? '_test' : '';

        return config("services.ups.{$key}{$suffix}");
    }

    /**
     * @param array $data {
     *   accountNumber: string,
     *   contactName: string, companyName: ?string, phone: string,
     *   address: string, city: string, postcode: string, countryCode: string,
     *   pickupDate: string (Ymd), readyTime: string (Hi), closeTime: string (Hi),
     *   totalWeightKg: float, totalPieces: int, serviceCode: string, destinationCountryCode: string,
     *   referenceNumber: ?string,
     * }
     * @return array{prn:?string,raw:array}
     */
    public function createPickup(string $token, array $data, ?string $mode = null): array
    {
        $body = [
            'PickupCreationRequest' => [
                'RatePickupIndicator' => 'N',
                'Shipper' => ['Account' => ['AccountNumber' => $data['accountNumber'], 'AccountCountryCode' => $data['countryCode']]],
                'PickupDateInfo' => [
                    'CloseTime' => $data['closeTime'],
                    'ReadyTime' => $data['readyTime'],
                    'PickupDate' => $data['pickupDate'],
                ],
                'PickupAddress' => [
                    'CompanyName' => $data['companyName'] ?: $data['contactName'],
                    'ContactName' => $data['contactName'],
                    'AddressLine' => [$data['address']],
                    'City' => $data['city'],
                    'PostalCode' => $data['postcode'],
                    'CountryCode' => $data['countryCode'],
                    'ResidentialIndicator' => 'N',
                    'Phone' => ['Number' => $data['phone']],
                ],
                'AlternateAddressIndicator' => 'N',
                'PickupPiece' => [[
                    'ServiceCode' => $data['serviceCode'],
                    'Quantity' => (string) $data['totalPieces'],
                    'DestinationCountryCode' => $data['destinationCountryCode'],
                    'ContainerCode' => '01',
                ]],
                'TotalWeight' => ['Weight' => (string) $data['totalWeightKg'], 'UnitOfMeasurement' => 'KGS'],
                'OverweightIndicator' => 'N',
                'PaymentMethod' => '01',
                'ReferenceNumber' => $data['referenceNumber'] ?? 'MADD Pickup',
            ],
        ];

        $response = Http::withToken($token)
            ->withHeaders(['transactionSrc' => config('services.ups.transaction_src', 'testing')])
            ->timeout(20)
            ->post($this->upsUrl('pickup_create_url', $mode), $body);

        $raw = $response->json();
        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS pickup creation failed: token expired');
            }
            throw new \RuntimeException('UPS pickup creation failed: '.($raw['response']['errors'][0]['message'] ?? $response->status()));
        }

        return [
            'prn' => $raw['PickupCreationResponse']['PRN'] ?? null,
            'raw' => $raw,
        ];
    }

    public function cancelPickup(string $token, string $prn, ?string $mode = null): array
    {
        $response = Http::withToken($token)
            ->withHeaders(['transactionSrc' => config('services.ups.transaction_src', 'testing'), 'Prn' => $prn])
            ->timeout(20)
            ->delete($this->upsUrl('pickup_cancel_url', $mode));

        $raw = $response->json();
        if (! $response->successful()) {
            if ($response->status() === 401) {
                throw new UpsTokenExpiredException('UPS pickup cancel failed: token expired');
            }
            throw new \RuntimeException('UPS pickup cancel failed: '.($raw['response']['errors'][0]['message'] ?? $response->status()));
        }

        return $raw;
    }
}
