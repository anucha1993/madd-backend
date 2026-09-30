<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ledger line of supply stock (see the create_supply_stock_tables migration for types). */
#[Fillable(['supply_id', 'branch_id', 'type', 'quantity', 'balance_after', 'shipment_id', 'reference', 'note', 'user_id', 'created_at'])]
class SupplyStockMovement extends Model
{
    public const TYPES = ['receive', 'adjust', 'shipment', 'shipment_return'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
