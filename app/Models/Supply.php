<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'type', 'weight', 'length', 'width', 'height', 'icon_url', 'weight_band_id', 'is_featured', 'cost_price', 'sale_price', 'description', 'status'])]
class Supply extends Model
{
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'is_featured' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function weightBand(): BelongsTo
    {
        return $this->belongsTo(ProductWeightBand::class, 'weight_band_id');
    }
}
