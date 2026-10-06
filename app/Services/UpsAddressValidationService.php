<?php

namespace App\Services;

use App\Services\Concerns\HasUpsOAuthToken;
use App\Support\StateCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UpsAddressValidationService
{
    use HasUpsOAuthToken;

    private function upsUrl(string $key, ?string $mode): string
    {
        $suffix = $mode === 'test' ? '_test' : '';

        return config("services.ups.{$key}{$suffix}");
    }

    /**
     * Runs UPS Street Level Address Validation + Classification (request option "3") against a
     * destination address. UPS returns exactly one of three outcomes: ValidAddressIndicator (the
     * address matches as typed), AmbiguousAddressIndicator (one or more suggested corrections),
     * or NoCandidatesIndicator (the address doesn't appear to exist).
     */
    public function validate(string $clientId, string $clientSecret, array $address, ?string $mode = null): array
    {
        $token = $this->getAccessToken($clientId, $clientSecret, $mode);

        $addressLines = array_values(array_filter([
            $address['address'] ?? null,
            $address['address2'] ?? null,
            $address['address3'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $payload = [
            'XAVRequest' => [
                // UPS's schema requires this Request node on every API call (like every other
                // UPS service in this codebase) — omitting it went unnoticed in the CIE/test
                // sandbox (which always short-circuits to its own generic state-not-supported
                // error regardless of payload) but caused production to fail request parsing
                // early and report a misleading "Country Code is invalid or missing" instead,
                // even when CountryCode was correctly populated below.
                'Request' => ['TransactionReference' => ['CustomerContext' => 'MADD Address Validation']],
                'AddressKeyFormat' => array_filter([
                    'AddressLine' => $addressLines ?: null,
                    'PoliticalDivision2' => $address['city'] ?? null,
                    'PoliticalDivision1' => StateCode::clean($address['stateCode'] ?? null),
                    'PostcodePrimaryLow' => $address['postcode'] ?? null,
                    'CountryCode' => $address['country'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        ];

        $url = rtrim($this->upsUrl('address_validation_url', $mode), '/').'/3';

        $response = Http::withToken($token)
            ->withHeaders([
                'transId' => (string) Str::uuid(),
                'transactionSrc' => config('services.ups.transaction_src', 'testing'),
            ])
            ->timeout(20)
            ->post($url, $payload);

        if (! $response->successful()) {
            $message = $response->json('response.errors.0.message')
                ?? $response->json('errors.0.message')
                ?? "UPS Address Validation request failed (HTTP {$response->status()})";
            throw new \RuntimeException($message);
        }

        return $this->normalize($response->json());
    }

    private function normalize(array $raw): array
    {
        $xav = $raw['XAVResponse'] ?? [];
        $candidates = $xav['Candidate'] ?? [];

        // UPS returns a single associative array when there's exactly one candidate, or a list
        // of them when there's more than one — normalize to always be a list.
        if (isset($candidates['AddressKeyFormat'])) {
            $candidates = [$candidates];
        }

        $normalizedCandidates = array_map(function (array $candidate) {
            $akf = $candidate['AddressKeyFormat'] ?? [];
            $addressLines = $akf['AddressLine'] ?? [];
            if (is_string($addressLines)) {
                $addressLines = [$addressLines];
            }

            $postcode = $akf['PostcodePrimaryLow'] ?? '';
            if (! empty($akf['PostcodeExtendedLow'])) {
                $postcode .= '-'.$akf['PostcodeExtendedLow'];
            }

            return [
                'addressLines' => array_values(array_filter((array) $addressLines)),
                'city' => $akf['PoliticalDivision2'] ?? null,
                'state' => $akf['PoliticalDivision1'] ?? null,
                'postcode' => $postcode ?: null,
                'country' => $akf['CountryCode'] ?? null,
                'classification' => match ($candidate['AddressClassification']['Code'] ?? null) {
                    '1' => 'commercial',
                    '2' => 'residential',
                    default => 'unknown',
                },
            ];
        }, $candidates);

        return [
            // Presence-based flags — UPS sends these as an empty string when set, absent when not.
            'valid' => array_key_exists('ValidAddressIndicator', $xav),
            'ambiguous' => array_key_exists('AmbiguousAddressIndicator', $xav),
            'noCandidates' => array_key_exists('NoCandidatesIndicator', $xav),
            'candidates' => $normalizedCandidates,
        ];
    }
}
