<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentAccount;
use App\Models\ChargeCode;
use App\Models\MarkupRule;
use Illuminate\Database\Seeder;

class MarkupRuleSeeder extends Seeder
{
    /**
     * Ported from the legacy constant-service `mark_up` table (production DB backup
     * ฺBK_DB/db-constant_service.sql). Only the consistent, real baseline rows are
     * kept — a flat 5.5% Freight markup applied per account (ids 24–31 in the old
     * dump). Rows 32–40 in that dump were excluded: they're Postman-test/dev
     * artifacts with mismatched charge codes (e.g. mark_key "FREIGHT" pointed at a
     * non-freight charge code, or a DHL account referencing a UPS-only code) and
     * negative "TEST_NEG"/"DISCOUNT" test entries — not real production config.
     */
    public function run(): void
    {
        $rows = [
            // provider, username_acc, code, value, unit, status
            ['UPS', '884v4f', 'BASE_SERVICE', 5.5, 'PERCENTAGE', false],
            ['UPS', '2w1278', 'BASE_SERVICE', 5.5, 'PERCENTAGE', false],
            ['UPS', '0279v5', 'BASE_SERVICE', 5.5, 'PERCENTAGE', true],
            ['UPS', '0279v4', 'BASE_SERVICE', 5.5, 'PERCENTAGE', true],
            ['UPS', '0512v7', 'BASE_SERVICE', 5.5, 'PERCENTAGE', true],
            ['UPS', 'a47094', 'BASE_SERVICE', 5.5, 'PERCENTAGE', true],
            ['UPS', 'ax3173', 'BASE_SERVICE', 5.5, 'PERCENTAGE', true],
            ['DHL', '560634572', 'BASE', 5.5, 'PERCENTAGE', true],
        ];

        foreach ($rows as [$provider, $usernameAcc, $code, $value, $unit, $status]) {
            $agent = Agent::where('agent_code', $provider)->first();
            $account = AgentAccount::where('agent_id', $agent?->id)->where('username_acc', $usernameAcc)->first();
            $chargeCode = ChargeCode::where('provider', $provider)->where('code', $code)->first();

            if (! $agent || ! $account || ! $chargeCode) {
                $this->command?->warn("ข้ามแถว: ไม่พบ agent/account/charge_code สำหรับ {$provider} {$usernameAcc} {$code}");

                continue;
            }

            MarkupRule::updateOrCreate(
                ['agent_account_id' => $account->id, 'charge_code_id' => $chargeCode->id],
                [
                    'agent_id' => $agent->id,
                    'value' => $value,
                    'unit' => $unit,
                    'status' => $status,
                ]
            );
        }
    }
}
