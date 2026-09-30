<?php

namespace App\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Carbon;
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
    use ScopedByAccess;

    protected static string $accessModule = 'pickup';

    protected $appends = ['collection'];

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

    /**
     * Whether the courier actually came — the carrier never links an on-call pickup to
     * tracking numbers, so this is derived from the attached shipments' own picked_up_at
     * (tracking scans / staff confirmation):
     * waiting -> partial -> collected, or overdue once the close time (Thai time) has passed
     * with something still uncollected. Null for cancelled/failed pickups or when the
     * shipments relation wasn't loaded.
     *
     * @return array{state:string, picked:int, total:int}|null
     */
    public function getCollectionAttribute(): ?array
    {
        if ($this->status !== 'requested' || ! $this->relationLoaded('shipments')) {
            return null;
        }
        $total = $this->shipments->count();
        $picked = $this->shipments->whereNotNull('picked_up_at')->count();

        $closesAt = $this->pickup_date
            ? Carbon::parse($this->pickup_date->format('Y-m-d').' '.($this->close_time ?: '23:59'), 'Asia/Bangkok')
            : null;
        $state = match (true) {
            $total > 0 && $picked === $total => 'collected',
            $closesAt !== null && $closesAt->isPast() => 'overdue',
            $picked > 0 => 'partial',
            default => 'waiting',
        };

        return ['state' => $state, 'picked' => $picked, 'total' => $total];
    }
}
