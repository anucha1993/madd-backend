<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_line_template_id', 'description', 'formula', 'is_non_vat', 'sort_order'])]
class ReceiptLineTemplateItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_non_vat' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReceiptLineTemplate::class, 'receipt_line_template_id');
    }
}
