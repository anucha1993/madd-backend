<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_id', 'sort_order', 'description', 'invoice_no', 'is_non_vat', 'amount'])]
class ReceiptLine extends Model
{
    protected function casts(): array
    {
        return [
            'is_non_vat' => 'boolean',
            'amount' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
