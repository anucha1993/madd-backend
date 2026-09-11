<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'label', 'package_type', 'min_weight', 'max_weight', 'sort_order', 'status'])]
class ProductWeightBand extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'min_weight' => 'decimal:2',
            'max_weight' => 'decimal:2',
            'status' => 'boolean',
        ];
    }
}
