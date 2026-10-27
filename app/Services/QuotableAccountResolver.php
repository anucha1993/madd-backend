<?php

namespace App\Services;

use App\Models\AgentAccount;
use App\Models\BranchCarrierAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which carrier accounts a user may quote/book with — shared by the real Check Rate
 * (ShippingController) and the AI rate chat so both offer the same accounts and services.
 *
 * - Only active accounts with a real API integration (is_api_enabled=false accounts, e.g.
 *   Kerry/Flash, are only ever used when issuing a Receipt manually).
 * - Accounts / services come from the user's OWN branch (/config/users "สาขา", the branch they
 *   book under) and its carrier accounts (/branch). The branches a user may SEE (branch access /
 *   "access all branches") never matter here — they only open other branches' Shipments.
 *   No own branch → no branch restriction. A branch with no accounts assigned → every account.
 * - Each assignment may restrict which service/product codes are allowed for that account
 *   (BranchCarrierAccount.allowed_service_codes); if the same account is assigned to several of
 *   the user's branches, any unrestricted (null) assignment wins.
 */
class QuotableAccountResolver
{
    /**
     * @return array{query: Builder, allowedServiceCodes: array<int, array<int,string>|null>}
     */
    public function forUser(?User $user): array
    {
        return $this->forBranches(self::branchIdsFor($user));
    }

    /** The branches whose carrier accounts this user quotes with (null = no restriction). */
    public static function branchIdsFor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }
        // Only the own branch — never the branches the user may merely view.
        return $user->branch_id ? [(int) $user->branch_id] : null;
    }

    /**
     * @param  array<int, int>|null  $branchIds  null = no branch restriction
     * @return array{query: Builder, allowedServiceCodes: array<int, array<int,string>|null>}
     */
    public function forBranches(?array $branchIds): array
    {
        $query = AgentAccount::with('agent')->where('status', true)->where('is_api_enabled', true);
        $allowedServiceCodes = [];

        if ($branchIds !== null) {
            $assignments = BranchCarrierAccount::whereIn('branch_id', $branchIds)->get();
            $allowedAccountIds = $assignments->pluck('agent_account_id')->unique();

            if ($allowedAccountIds->isNotEmpty()) {
                $query->whereIn('id', $allowedAccountIds);
            }

            foreach ($assignments->groupBy('agent_account_id') as $accountId => $rows) {
                $unrestricted = $rows->contains(fn ($r) => empty($r->allowed_service_codes));
                $allowedServiceCodes[$accountId] = $unrestricted
                    ? null
                    : $rows->flatMap(fn ($r) => $r->allowed_service_codes)->unique()->values()->all();
            }
        }

        return ['query' => $query, 'allowedServiceCodes' => $allowedServiceCodes];
    }
}
