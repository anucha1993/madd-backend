<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['iso2', 'name', 'region', 'subregion', 'status', 'synced_at'])]
class Country extends Model
{
    use Auditable;

    // Refreshed on every country sync — not a change anyone made.
    protected array $auditExclude = ['synced_at'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
