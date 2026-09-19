<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'agent_account_id', 'branch_id', 'created_by', 'carrier', 'service_code', 'service_label',
    'tracking_number', 'pieces', 'status', 'origin', 'destination', 'packages', 'addon_lines',
    'freight_amount', 'addon_total', 'order_total', 'currency',
    'customer_type', 'entity_type', 'payment_method', 'bill_transportation_to', 'bill_duty_tax_to',
    'ref_invoice_no', 'ref_insurance_no', 'ref_purchase_no', 'rate_quote',
    'label_storage_key', 'waybill_storage_key', 'commercial_invoice_storage_key', 'raw_response', 'error_message',
    'voided_at', 'void_note',
    'tracking_status', 'tracking_raw_status', 'tracking_synced_at', 'delivered_at',
])]
class Shipment extends Model
{
    protected $appends = ['is_test'];

    protected function casts(): array
    {
        return [
            'origin' => 'array',
            'destination' => 'array',
            'packages' => 'array',
            'pieces' => 'array',
            'addon_lines' => 'array',
            'raw_response' => 'array',
            'rate_quote' => 'array',
            'freight_amount' => 'decimal:2',
            'addon_total' => 'decimal:2',
            'order_total' => 'decimal:2',
            'voided_at' => 'datetime',
            'tracking_synced_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(AgentAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pickups(): BelongsToMany
    {
        return $this->belongsToMany(Pickup::class, 'pickup_shipment');
    }

    public function receipts(): BelongsToMany
    {
        return $this->belongsToMany(Receipt::class, 'receipt_shipment');
    }

    /**
     * A shipment booked against a Test-mode Agent Account (sandbox UPS/DHL credentials) is safe
     * to fully delete (not just void) — see ShipmentController::destroy(). Real production
     * bookings can only ever be Voided, never deleted.
     */
    public function getIsTestAttribute(): bool
    {
        return $this->agentAccount?->mode === 'test';
    }
}
