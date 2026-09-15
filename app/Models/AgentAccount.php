<?php

namespace App\Models;

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
    'status', 'mode',
])]
#[Hidden(['client_secret', 'basic_auth_password'])]
class AgentAccount extends Model
{
    use HasFactory;

    protected $appends = ['has_client_secret', 'has_basic_auth_password'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
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
