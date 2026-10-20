<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Casts\CarrierSecret;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agent_id', 'username_acc', 'api_key',
    'client_id', 'client_secret',
    'basic_auth_username', 'basic_auth_password',
    'status', 'mode', 'is_api_enabled',
    'rate_min_weight', 'rate_max_weight', 'service_weight_ranges',
])]
#[Hidden(['client_secret', 'basic_auth_password'])]
class AgentAccount extends Model
{
    use Auditable, HasFactory;

    protected $appends = ['has_client_secret', 'has_basic_auth_password'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'is_api_enabled' => 'boolean',
            'rate_min_weight' => 'float',
            'rate_max_weight' => 'float',
            'service_weight_ranges' => 'array',
            'client_secret' => CarrierSecret::class,
            'basic_auth_password' => CarrierSecret::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function branchCarrierAccounts(): HasMany
    {
        return $this->hasMany(BranchCarrierAccount::class);
    }

    public function chargeFixedOverrides(): HasMany
    {
        return $this->hasMany(ChargeFixedOverride::class);
    }

    /**
     * Whether Check Rate asks this account at all for a shipment of this chargeable weight: its own
     * range (/config/agent-accounts, e.g. ax3173 = 0-10 kg) or any service's own range covers it.
     */
    public function quotesWeight(float $weight): bool
    {
        return self::inRange($weight, $this->rate_min_weight, $this->rate_max_weight)
            || collect($this->serviceRanges())->contains(fn ($r) => self::inRange($weight, $r['min'], $r['max']) || self::belowWithMinCharge($weight, $r));
    }

    /**
     * Whether one service of this account is offered at this weight: the service's own range
     * (e.g. UPS 08 Expedited 10.01–70 kg), else the account's range — no range = every weight.
     */
    public function quotesService(?string $serviceCode, float $weight): bool
    {
        $range = collect($this->serviceRanges())->first(fn ($r) => strcasecmp($r['service_code'], (string) $serviceCode) === 0);

        return $range
            ? self::inRange($weight, $range['min'], $range['max']) || self::belowWithMinCharge($weight, $range)
            : self::inRange($weight, $this->rate_min_weight, $this->rate_max_weight);
    }

    /**
     * The weight a service is PRICED at when the shipment is under its range but the range has a
     * minimum charge (e.g. Expedited 25.01–70, min charge 25 → a 10 kg shipment is sold as 25 kg;
     * the booking still sends 10 kg). Null = priced at its own weight.
     */
    public function minChargeFor(?string $serviceCode, float $weight): ?float
    {
        $range = collect($this->serviceRanges())->first(fn ($r) => strcasecmp($r['service_code'], (string) $serviceCode) === 0);

        return $range && self::belowWithMinCharge($weight, $range) ? $range['min_charge'] : null;
    }

    private static function belowWithMinCharge(float $weight, array $range): bool
    {
        return $range['min_charge'] !== null && $range['min'] !== null && $weight < $range['min'] - 0.005;
    }

    /**
     * Drops quotes for services this account doesn't offer at this weight (errors are kept so a
     * failing account still shows up).
     *
     * @param  iterable<self>  $accounts
     */
    public static function filterQuotesByWeight(array $results, iterable $accounts, float $weight): array
    {
        $byId = collect($accounts)->keyBy('id');

        return array_values(array_filter($results, function ($r) use ($byId, $weight) {
            $account = $byId->get($r['accountId'] ?? null);

            if (! empty($r['error']) || ! $account) {
                return true;
            }
            // Under a min-charge range only the min-charge quote may be sold (MinChargeQuoter) —
            // never the real-weight price, even if the re-quote failed.
            if ($account->minChargeFor($r['serviceCode'] ?? null, $weight) !== null && empty($r['minChargeWeight'])) {
                return false;
            }

            return $account->quotesService($r['serviceCode'] ?? null, $weight);
        }));
    }

    /** @return list<array{service_code: string, min: ?float, max: ?float, min_charge: ?float}> */
    public function serviceRanges(): array
    {
        return array_values(array_map(fn ($r) => [
            'service_code' => (string) ($r['service_code'] ?? ''),
            'min' => isset($r['min']) && $r['min'] !== '' ? (float) $r['min'] : null,
            'max' => isset($r['max']) && $r['max'] !== '' ? (float) $r['max'] : null,
            // Under `min`: still sold, priced at this weight (null = not sold under the range).
            'min_charge' => isset($r['min_charge']) && $r['min_charge'] !== '' ? (float) $r['min_charge'] : null,
        ], $this->service_weight_ranges ?? []));
    }

    private static function inRange(float $weight, ?float $min, ?float $max): bool
    {
        return ($min === null || $weight >= $min - 0.005) && ($max === null || $weight <= $max + 0.005);
    }

    /**
     * True when a secret was previously saved, without ever exposing its value.
     */
    public function getHasClientSecretAttribute(): bool
    {
        return ! empty($this->attributes['client_secret']);
    }

    public function getHasBasicAuthPasswordAttribute(): bool
    {
        return ! empty($this->attributes['basic_auth_password']);
    }
}
