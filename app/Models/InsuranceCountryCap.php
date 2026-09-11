<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'country_name', 'country_code',
    'ups_max_value', 'dhl_max_value',
    'ups_max_declared', 'dhl_max_declared',
    'note',
])]
class InsuranceCountryCap extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ups_max_value' => 'decimal:2',
            'dhl_max_value' => 'decimal:2',
            'ups_max_declared' => 'decimal:2',
            'dhl_max_declared' => 'decimal:2',
        ];
    }
}
