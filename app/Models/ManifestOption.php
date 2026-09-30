<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group', 'provider', 'name', 'code', 'amount', 'sort_order', 'status'])]
class ManifestOption extends Model
{
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => 'boolean',
        ];
    }
}
