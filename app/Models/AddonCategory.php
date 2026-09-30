<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order'])]
class AddonCategory extends Model
{
    use Auditable;

    public function items(): HasMany
    {
        return $this->hasMany(AddonItem::class);
    }
}
