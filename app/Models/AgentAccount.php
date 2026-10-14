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
    'rate_min_weight', 'rate_max_weight',
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
     * Whether Check Rate asks this account for a shipment of this chargeable weight — its own
     * weight range (/config/agent-accounts, e.g. ax3173 = 0-10 kg); no range = every weight.
     */
    public function quotesWeight(float $weight): bool
    {
        return ($this->rate_min_weight === null || $weight >= $this->rate_min_weight - 0.005)
            && ($this->rate_max_weight === null || $weight <= $this->rate_max_weight + 0.005);
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
