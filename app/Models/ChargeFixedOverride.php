<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_account_id', 'charge_code_id', 'override_type', 'formula', 'fixed_amount', 'unit', 'status'])]
class ChargeFixedOverride extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fixed_amount' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(AgentAccount::class);
    }

    public function chargeCode(): BelongsTo
    {
        return $this->belongsTo(ChargeCode::class);
    }
}
