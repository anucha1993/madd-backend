<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\Country;
use App\Models\SystemAlert;

/**
 * Sell-price quotes for the Public Rate API: the same carrier accounts/services as the client's
 * branch and the same markup as the counter's Check Rate (ChargeMarkupService), reduced to a
 * WHITELIST of customer-facing fields — cost, markup, breakdown, account numbers and raw
 * carrier data never leave this class.
 */
class PublicRateService
{
    public function __construct(
        private UpsRateService $ups,
        private DhlRateService $dhl,
        private ChargeMarkupService $markup,
        private QuotableAccountResolver $accounts,
    ) {}

    /**
     * @param  array{destination: array{country: string, city?: ?string, postcode?: ?string}, origin?: array{postcode?: ?string, city?: ?string}, shipment_type: string, packages: array<int, array{weight: float, length?: ?float, width?: ?float, height?: ?float, quantity?: ?int}>}  $input
     * @return array<int, array{carrier: string, service_code: string, service_name: string, price: float, currency: string, transit_days: ?int, estimated_delivery: ?string}>
     */
    public function quote(ApiClient $client, array $input): array
    {
        $isDocument = $input['shipment_type'] === 'document';
        $country = strtoupper($input['destination']['country']);
        $city = $input['destination']['city'] ?? null;
        $postcode = $input['destination']['postcode'] ?? null;
        // Country only: quote to its main hub — DHL rejects a destination without a real city /
        // postal code (see config/destination_defaults.php).
        if (! $city && ! $postcode) {
            [$city, $postcode] = config("destination_defaults.{$country}") ?? [Country::where('iso2', $country)->value('name') ?? $country, null];
        }
        $city = $city ?: (config("destination_defaults.{$country}.0") ?? $country);
        $shipment = [
            'from' => [
                'country' => 'TH',
                'city' => $input['origin']['city'] ?? null ?: $client->origin_city,
                'postcode' => $input['origin']['postcode'] ?? null ?: $client->origin_postcode,
                'address' => $input['origin']['city'] ?? null ?: $client->origin_city,
            ],
            'to' => [
                'country' => $country,
                'city' => $city,
                'postcode' => $postcode ?? '',
                'address' => $city, // DHL rejects an empty addressLine1
            ],
            'packages' => array_map(fn ($p) => [
                'weight' => (float) $p['weight'],
                'length' => $p['length'] ?? ($isDocument ? null : 20),
                'width' => $p['width'] ?? ($isDocument ? null : 15),
                'height' => $p['height'] ?? ($isDocument ? null : 10),
                'quantity' => max(1, (int) ($p['quantity'] ?? 1)),
                'isDocument' => $isDocument,
            ], $input['packages']),
        ];

        ['query' => $query, 'allowedServiceCodes' => $allowed] = $this->accounts->forBranches($client->branch_id ? [$client->branch_id] : null);
        $accounts = $query->get();
        $carriers = $client->carriers ?: ['UPS', 'DHL'];
        $ups = $accounts->filter(fn ($a) => in_array('UPS', $carriers, true) && $a->agent?->agent_code === 'UPS' && $a->client_id && $a->client_secret);
        $dhl = $accounts->filter(fn ($a) => in_array('DHL', $carriers, true) && $a->agent?->agent_code === 'DHL' && $a->basic_auth_username && $a->basic_auth_password);

        $results = [
            ...$this->ups->quoteAccounts(
                $ups->map(fn ($a) => ['id' => $a->id, 'username_acc' => $a->username_acc, 'client_id' => $a->client_id, 'client_secret' => $a->client_secret, 'mode' => $a->mode, 'allowed_service_codes' => $allowed[$a->id] ?? null])->values()->all(),
                $shipment,
                array_map('strval', array_keys($this->ups->serviceLabels())),
            ),
            ...$this->dhl->quoteAccounts(
                $dhl->map(fn ($a) => ['id' => $a->id, 'username_acc' => $a->username_acc, 'basic_auth_username' => $a->basic_auth_username, 'basic_auth_password' => $a->basic_auth_password, 'mode' => $a->mode, 'allowed_service_codes' => $allowed[$a->id] ?? null])->values()->all(),
                $shipment,
            ),
        ];

        $ok = array_values(array_filter($results, fn ($r) => empty($r['error'])));
        if (! $ok && $results) {
            SystemAlert::record('public_api', "Public Rate API ({$client->name}): ไม่ได้ราคาจาก Carrier เลย", [
                'destination' => $shipment['to']['country'],
                'errors' => array_slice(array_map(fn ($r) => ($r['carrier'] ?? '').': '.mb_substr((string) $r['error'], 0, 200), $results), 0, 5),
            ], 'warning');
        }

        $options = [];
        foreach ($this->markup->applyToResults($ok) as $r) {
            $price = $r['negotiated'] ?? $r['published'] ?? null;
            if ($price === null) {
                continue;
            }
            $key = $r['carrier'].'|'.$r['serviceCode'];
            $option = [
                'carrier' => $r['carrier'],
                'service_code' => (string) $r['serviceCode'],
                'service_name' => (string) ($r['serviceLabel'] ?? $r['serviceCode']),
                'price' => $this->round((float) $price, $client->price_rounding),
                'currency' => $r['currency'] ?? 'THB',
                'transit_days' => isset($r['transitDays']) ? (int) $r['transitDays'] : null,
                'estimated_delivery' => $r['estimatedDelivery'] ?? null,
            ];
            // Several accounts can quote the same service — the customer only sees the best price.
            if (! isset($options[$key]) || $option['price'] < $options[$key]['price']) {
                $options[$key] = $option;
            }
        }
        $options = array_values($options);
        usort($options, fn ($a, $b) => $a['price'] <=> $b['price']);

        return array_slice($options, 0, max(1, $client->max_results));
    }

    private function round(float $price, int $step): float
    {
        return $step > 0 ? (float) (ceil(round($price, 2) / $step) * $step) : round($price, 2);
    }
}
