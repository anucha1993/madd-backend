<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'status', 'sort_order'])]
class ReceiptLineTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptLineTemplateItem::class)->orderBy('sort_order');
    }
}
