<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent_account_id', 'created_by', 'carrier', 'service_code', 'service_label',
    'tracking_number', 'status', 'origin', 'destination', 'packages', 'addon_lines',
    'freight_amount', 'addon_total', 'order_total', 'currency',
    'customer_type', 'entity_type', 'payment_method', 'bill_transportation_to', 'bill_duty_tax_to',
    'ref_invoice_no', 'ref_insurance_no', 'ref_purchase_no', 'rate_quote',
    'label_storage_key', 'raw_response', 'error_message',
])]
class Shipment extends Model
{
    protected function casts(): array
    {
        return [
            'origin' => 'array',
            'destination' => 'array',
            'packages' => 'array',
            'addon_lines' => 'array',
            'raw_response' => 'array',
            'rate_quote' => 'array',
            'freight_amount' => 'decimal:2',
            'addon_total' => 'decimal:2',
            'order_total' => 'decimal:2',
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
}
