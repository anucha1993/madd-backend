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
 * - Users without "access all branches" are limited to their branches' assigned accounts
 *   (branches with no assignments configured yet fall back to every active account).
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
        $query = AgentAccount::with('agent')->where('status', true)->where('is_api_enabled', true);
        $allowedServiceCodes = [];

        if ($user && ! $user->can_access_all_branches) {
            $branchIds = $user->branches()->pluck('branches.id');
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
