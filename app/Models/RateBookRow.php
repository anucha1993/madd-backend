<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'rate_book_run_id', 'carrier', 'package_type', 'zone', 'country_iso2', 'band_label', 'weight', 'is_per_kg',
    'agent_account_id', 'account_username', 'service_code', 'full',
    'freight', 'fuel', 'surge', 'remote', 'peak', 'gogreen', 'other', 'cost', 'markup', 'rounding', 'sell', 'vat', 'total', 'error',
])]
class RateBookRow extends Model
{
    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'is_per_kg' => 'boolean',
        ] + array_fill_keys(['full', 'freight', 'fuel', 'surge', 'remote', 'peak', 'gogreen', 'other', 'cost', 'markup', 'rounding', 'sell', 'vat', 'total'], 'float');
    }
}
