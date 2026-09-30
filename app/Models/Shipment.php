<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HidesRestrictedFields;
use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'agent_account_id', 'branch_id', 'created_by', 'carrier', 'service_code', 'service_label',
    'tracking_number', 'pieces', 'status', 'origin', 'destination', 'packages', 'addon_lines',
    'invoice_mode', 'invoice_lines',
    'freight_amount', 'addon_total', 'order_total', 'currency', 'cost_amount', 'cost_currency',
    'customer_type', 'entity_type', 'payment_method', 'bill_transportation_to', 'bill_duty_tax_to',
    'bill_transportation_account_number', 'bill_transportation_third_party_country', 'bill_transportation_third_party_postal_code',
    'bill_duty_tax_account_number', 'bill_duty_tax_third_party_country', 'bill_duty_tax_third_party_postal_code',
    'ref_invoice_no', 'ref_insurance_no', 'ref_purchase_no', 'rate_quote',
    'label_storage_key', 'waybill_storage_key', 'commercial_invoice_storage_key', 'raw_response', 'raw_request', 'carrier_http_status', 'error_message',
    'voided_at', 'void_note',
    'tracking_status', 'tracking_raw_status', 'tracking_synced_at', 'delivered_at',
    'picked_up_at', 'picked_up_source', 'picked_up_by',
])]
class Shipment extends Model
{
    use Auditable, HidesRestrictedFields, ScopedByAccess;

    protected array $auditExclude = ['raw_response', 'raw_request', 'rate_quote', 'packages', 'origin', 'destination', 'invoice_lines', 'addon_lines', 'pieces', 'tracking_raw_status', 'tracking_synced_at'];

    protected static string $accessModule = 'shipment';

    protected $appends = ['is_test'];

    protected function casts(): array
    {
        return [
            'origin' => 'array',
            'destination' => 'array',
            'packages' => 'array',
            'pieces' => 'array',
            'addon_lines' => 'array',
            'invoice_lines' => 'array',
            'raw_response' => 'array',
            'raw_request' => 'array',
            'rate_quote' => 'array',
            'freight_amount' => 'decimal:2',
            'addon_total' => 'decimal:2',
            'order_total' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'voided_at' => 'datetime',
            'tracking_synced_at' => 'datetime',
            'delivered_at' => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    /**
     * The saved rate_quote snapshot holds carrier cost / markup detail / raw response — strip
     * whatever the viewer's `rate` field access hides (see AccessService::sanitizeRateQuote),
     * keeping the sell-price breakdown the view page and receipts need.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        if (is_array($array['rate_quote'] ?? null) && ($user = auth()->user())) {
            $array['rate_quote'] = app(\App\Services\AccessService::class)->sanitizeRateQuote($user, $array['rate_quote']);
        }

        return $array;
    }

    /**
     * Staff saw the courier take it — counts as collected immediately (grouping, Pickup
     * progress) until the carrier's own scan arrives and replaces picked_up_at/source.
     */
    public function markPickedUpManually(?User $user): void
    {
        if ($this->picked_up_at) {
            return;
        }
        $this->update([
            'picked_up_at' => now(),
            'picked_up_source' => 'manual',
            'picked_up_by' => $user?->id,
            'tracking_status' => in_array($this->tracking_status, ['in_transit', 'delivered'], true) ? $this->tracking_status : 'in_transit',
        ]);
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
