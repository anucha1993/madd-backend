<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchCarrierAccountController extends Controller
{
    /**
     * List the carrier accounts assigned to a branch.
     */
    public function index(Branch $branch)
    {
        return $branch->carrierAccounts()->with('agentAccount.agent')->get();
    }

    /**
     * Replace the full set of carrier accounts assigned to a branch.
     * An empty list means "no restriction" — the branch may use any active account.
     */
    public function sync(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'accounts' => ['present', 'array'],
            'accounts.*.agent_account_id' => ['required', 'distinct', 'integer', 'exists:agent_accounts,id'],
            'accounts.*.label' => ['nullable', 'string', 'max:255'],
            'accounts.*.tracking_prefix' => ['nullable', 'string', 'max:50'],
            'accounts.*.is_default' => ['boolean'],
            // Which service/product codes this branch may use with this account — null/empty = all allowed.
            'accounts.*.allowed_service_codes' => ['nullable', 'array'],
            'accounts.*.allowed_service_codes.*' => ['string', 'max:20'],
        ]);

        DB::transaction(function () use ($branch, $data) {
            $branch->carrierAccounts()->delete();

            foreach ($data['accounts'] as $row) {
                $branch->carrierAccounts()->create([
                    'agent_account_id' => $row['agent_account_id'],
                    'label' => $row['label'] ?? null,
                    'tracking_prefix' => $row['tracking_prefix'] ?? null,
                    'is_default' => $row['is_default'] ?? false,
                    'allowed_service_codes' => ! empty($row['allowed_service_codes']) ? $row['allowed_service_codes'] : null,
                ]);
            }
        });

        return $branch->carrierAccounts()->with('agentAccount.agent')->get();
    }
}
