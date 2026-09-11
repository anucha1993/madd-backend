<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['iso2', 'name', 'region', 'subregion', 'status', 'synced_at'])]
class Country extends Model
{
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
