<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'agent_account_id', 'created_by', 'carrier', 'status', 'pickup_date', 'ready_time', 'close_time',
    'address', 'total_weight', 'total_pieces', 'carrier_reference', 'raw_response', 'error_message',
    'cancelled_at',
])]
class Pickup extends Model
{
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'raw_response' => 'array',
            'pickup_date' => 'date',
            'total_weight' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(AgentAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'pickup_shipment');
    }
}
