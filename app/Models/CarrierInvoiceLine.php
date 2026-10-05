<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'carrier_invoice_id', 'sort_order', 'tracking_number', 'reference_text', 'description',
    'charges', 'discount', 'amount', 'shipment_id', 'is_matched', 'override_amount', 'override_note',
])]
class CarrierInvoiceLine extends Model
{
    use Auditable;

    protected $appends = ['effective_amount'];

    protected function casts(): array
    {
        return [
            'charges' => 'decimal:2',
            'discount' => 'decimal:2',
            'amount' => 'decimal:2',
            'override_amount' => 'decimal:2',
            'is_matched' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoice::class, 'carrier_invoice_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** The amount to treat as "the real cost" once staff has corrected it — falls back to the OCR'd amount. */
    public function getEffectiveAmountAttribute(): ?string
    {
        return $this->override_amount ?? $this->amount;
    }
}
