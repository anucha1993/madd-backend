<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Services\DhlRateService;
use App\Services\UpsRateService;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        private UpsRateService $upsRateService,
        private DhlRateService $dhlRateService,
    ) {
    }

    /**
     * Compare UPS/DHL rates for a shipment. Origin is always Thailand;
     * destination must always be an international (non-Thailand) address.
     */
    public function checkRate(Request $request)
    {
        $data = $request->validate([
            'origin_postcode' => ['required', 'string', 'max:10'],
            'origin_city' => ['required', 'string', 'max:255'],
            'origin_address' => ['required', 'string', 'max:1000'],

            'destination_country' => ['required', 'string', 'size:2', 'not_in:TH'],
            'destination_city' => ['required', 'string', 'max:255'],
            'destination_postcode' => ['nullable', 'string', 'max:20'],
            'destination_address' => ['nullable', 'string', 'max:1000'],

            'packages' => ['required', 'array', 'min:1'],
            'packages.*.weight' => ['required', 'numeric', 'min:0.01'],
            'packages.*.is_document' => ['boolean'],
            'packages.*.length' => ['required_if:packages.*.is_document,false', 'nullable', 'numeric', 'min:1'],
            'packages.*.width' => ['required_if:packages.*.is_document,false', 'nullable', 'numeric', 'min:1'],
            'packages.*.height' => ['required_if:packages.*.is_document,false', 'nullable', 'numeric', 'min:1'],
            'packages.*.quantity' => ['nullable', 'integer', 'min:1'],

            'agent_account_ids' => ['nullable', 'array'],
            'agent_account_ids.*' => ['integer'],
            'service_codes' => ['nullable', 'array'],
            'service_codes.*' => ['string'],
        ]);

        $shipment = [
            'from' => [
                'country' => 'TH',
                'city' => $data['origin_city'],
                'postcode' => $data['origin_postcode'],
                'address' => $data['origin_address'] ?? '',
            ],
            'to' => [
                'country' => strtoupper($data['destination_country']),
                'city' => $data['destination_city'],
                'postcode' => $data['destination_postcode'] ?? '',
                'address' => $data['destination_address'] ?? '',
            ],
            'packages' => array_map(fn ($pkg) => [
                'weight' => $pkg['weight'],
                'length' => $pkg['length'] ?? null,
                'width' => $pkg['width'] ?? null,
                'height' => $pkg['height'] ?? null,
                'quantity' => $pkg['quantity'] ?? 1,
                'isDocument' => (bool) ($pkg['is_document'] ?? false),
            ], $data['packages']),
        ];

        $accountsQuery = AgentAccount::with('agent')->where('status', true);
        if (! empty($data['agent_account_ids'])) {
            $accountsQuery->whereIn('id', $data['agent_account_ids']);
        }
        $accounts = $accountsQuery->get();

        $upsAccounts = $accounts->filter(fn ($a) => $a->agent?->agent_code === 'UPS' && $a->client_id && $a->client_secret);
        $dhlAccounts = $accounts->filter(fn ($a) => $a->agent?->agent_code === 'DHL' && $a->basic_auth_username && $a->basic_auth_password);

        if ($upsAccounts->isEmpty() && $dhlAccounts->isEmpty()) {
            return response()->json(['error' => 'ไม่พบบัญชี UPS หรือ DHL ที่เปิดใช้งานอยู่'], 400);
        }

        $serviceCodes = ! empty($data['service_codes'])
            ? $data['service_codes']
            : array_map('strval', array_keys($this->upsRateService->serviceLabels()));

        $upsResults = $this->upsRateService->quoteAccounts(
            $upsAccounts->map(fn ($account) => [
                'id' => $account->id,
                'username_acc' => $account->username_acc,
                'client_id' => $account->client_id,
                'client_secret' => $account->client_secret,
            ])->values()->all(),
            $shipment,
            $serviceCodes,
        );

        $dhlResults = $this->dhlRateService->quoteAccounts(
            $dhlAccounts->map(fn ($account) => [
                'id' => $account->id,
                'username_acc' => $account->username_acc,
                'basic_auth_username' => $account->basic_auth_username,
                'basic_auth_password' => $account->basic_auth_password,
            ])->values()->all(),
            $shipment,
        );

        return response()->json([
            'accountCount' => $upsAccounts->count() + $dhlAccounts->count(),
            'results' => [...$upsResults, ...$dhlResults],
        ]);
    }
}
