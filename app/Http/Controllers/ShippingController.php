<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Models\BranchCarrierAccount;
use App\Services\ChargeMarkupService;
use App\Services\DhlRateService;
use App\Services\UpsRateService;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        private UpsRateService $upsRateService,
        private DhlRateService $dhlRateService,
        private ChargeMarkupService $chargeMarkupService,
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
            // Declared Value is set PER PACKAGE (UPS insurance charge is a package-level field,
            // per PackageServiceOptions.DeclaredValue) — sending it lets UPS/DHL quote back the
            // actual insurance charge in chargeBreakdown, instead of it being estimated separately.
            'packages.*.declared_value' => ['nullable', 'numeric', 'min:0'],
            'declared_value_currency' => ['nullable', 'string', 'size:3'],

            'agent_account_ids' => ['nullable', 'array'],
            'agent_account_ids.*' => ['integer'],
            // Lets staff check only UPS, only DHL, or both (default = both) before running the rate check.
            'carriers' => ['nullable', 'array'],
            'carriers.*' => ['string', 'in:UPS,DHL'],
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
                'declaredValue' => $pkg['declared_value'] ?? null,
            ], $data['packages']),
            'declaredValueCurrency' => $data['declared_value_currency'] ?? 'THB',
        ];

        $accountsQuery = AgentAccount::with('agent')->where('status', true);

        // Branches without "access all" restrict quoting to their assigned accounts
        // (branches with no assignments configured yet fall back to every active account).
        // Each assignment may also restrict which service/product codes are allowed for that
        // account (BranchCarrierAccount.allowed_service_codes) — collected here so it can be
        // applied per-account below. If the same account is assigned to multiple of the user's
        // branches, any branch with no restriction (null) wins (i.e. stays unrestricted).
        $user = $request->user();
        $allowedServiceCodesByAccountId = [];
        if ($user && ! $user->can_access_all_branches) {
            $branchIds = $user->branches()->pluck('branches.id');
            $branchCarrierAccounts = BranchCarrierAccount::whereIn('branch_id', $branchIds)->get();
            $allowedAccountIds = $branchCarrierAccounts->pluck('agent_account_id')->unique();

            if ($allowedAccountIds->isNotEmpty()) {
                $accountsQuery->whereIn('id', $allowedAccountIds);
            }

            foreach ($branchCarrierAccounts->groupBy('agent_account_id') as $accountId => $rows) {
                $unrestricted = $rows->contains(fn ($r) => empty($r->allowed_service_codes));
                $allowedServiceCodesByAccountId[$accountId] = $unrestricted
                    ? null
                    : $rows->flatMap(fn ($r) => $r->allowed_service_codes)->unique()->values()->all();
            }
        }

        if (! empty($data['agent_account_ids'])) {
            $accountsQuery->whereIn('id', $data['agent_account_ids']);
        }
        $accounts = $accountsQuery->get();

        $carriers = ! empty($data['carriers']) ? $data['carriers'] : ['UPS', 'DHL'];
        $upsAccounts = in_array('UPS', $carriers, true)
            ? $accounts->filter(fn ($a) => $a->agent?->agent_code === 'UPS' && $a->client_id && $a->client_secret)
            : collect();
        $dhlAccounts = in_array('DHL', $carriers, true)
            ? $accounts->filter(fn ($a) => $a->agent?->agent_code === 'DHL' && $a->basic_auth_username && $a->basic_auth_password)
            : collect();

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
                'mode' => $account->mode,
                'allowed_service_codes' => $allowedServiceCodesByAccountId[$account->id] ?? null,
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
                'mode' => $account->mode,
                'allowed_service_codes' => $allowedServiceCodesByAccountId[$account->id] ?? null,
            ])->values()->all(),
            $shipment,
        );

        $results = $this->chargeMarkupService->applyToResults([...$upsResults, ...$dhlResults]);

        return response()->json([
            'accountCount' => $upsAccounts->count() + $dhlAccounts->count(),
            'results' => $results,
        ]);
    }
}
