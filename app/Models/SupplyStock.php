<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Current balance + Min/Max of one supply at one branch — changed only via SupplyStockService. */
#[Fillable(['supply_id', 'branch_id', 'quantity', 'min_qty', 'max_qty'])]
class SupplyStock extends Model
{
    protected $appends = ['level'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'min_qty' => 'integer', 'max_qty' => 'integer'];
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** 'low' at/below Min, 'over' above Max, otherwise 'ok'. */
    public function getLevelAttribute(): string
    {
        if ($this->min_qty !== null && $this->quantity <= $this->min_qty) {
            return 'low';
        }
        if ($this->max_qty !== null && $this->quantity > $this->max_qty) {
            return 'over';
        }

        return 'ok';
    }
}
