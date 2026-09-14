<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['addon_category_id', 'name', 'carriers', 'customer_types', 'price_type', 'price', 'trigger_type', 'status', 'note'])]
class AddonItem extends Model
{
    protected function casts(): array
    {
        return [
            'carriers' => 'array',
            'customer_types' => 'array',
            'price' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AddonCategory::class, 'addon_category_id');
    }
}
