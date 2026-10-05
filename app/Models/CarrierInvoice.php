<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'carrier', 'agent_account_id', 'invoice_no', 'invoice_date', 'total_amount', 'currency',
    'storage_key', 'original_filename', 'status', 'ocr_text', 'error_message', 'created_by',
])]
class CarrierInvoice extends Model
{
    use Auditable;

    protected array $auditExclude = ['ocr_text'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(AgentAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CarrierInvoiceLine::class)->orderBy('sort_order');
    }
}
