<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order'])]
class AddonCategory extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(AddonItem::class);
    }
}
