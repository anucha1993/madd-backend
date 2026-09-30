<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HidesRestrictedFields;
use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'receipt_group_id', 'branch_id', 'vol_no', 'no', 'issued_date',
    'billing_customer_id', 'buyer_name', 'buyer_tax_id', 'buyer_address', 'buyer_is_head_office', 'buyer_branch_no',
    'subtotal_non_vat', 'subtotal_vat', 'vat_rate', 'vat_amount', 'grand_total', 'grand_total_words',
    'shipment_total_snapshot', 'manual_shipment_refs',
    'payment_method', 'payment_reference',
    'status', 'voided_at', 'void_note', 'created_by',
])]
class Receipt extends Model
{
    use Auditable, HidesRestrictedFields, ScopedByAccess;

    protected static string $accessModule = 'receipt';

    protected $appends = ['is_test', 'variance_amount'];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'buyer_is_head_office' => 'boolean',
            'subtotal_non_vat' => 'decimal:2',
            'subtotal_vat' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'shipment_total_snapshot' => 'decimal:2',
            'manual_shipment_refs' => 'array',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Grand total minus the shipment sell-price snapshot at issue time — positive when the buyer
     * was billed MORE than the shipment cost (allowed since 2026-09-24), negative if less, null
     * for legacy documents issued before this was tracked. This is the "ส่วนต่าง" surfaced in the
     * Issue form, Receipts list, and (eventually) Reports.
     */
    public function getVarianceAmountAttribute(): ?string
    {
        if ($this->shipment_total_snapshot === null) {
            return null;
        }

        return (string) round((float) $this->grand_total - (float) $this->shipment_total_snapshot, 2);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('sort_order');
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'receipt_shipment')->withPivot('type');
    }

    /**
     * The other half of this receipt_group_id pair (a Cash Receipt <-> Tax Invoice issued
     * together, see 2026-09-23 paired-issuance change) — null for pre-existing standalone
     * documents issued before this change, or an orphaned/already-deleted pair.
     */
    public function pairedReceipt(): ?self
    {
        if (! $this->receipt_group_id) {
            return null;
        }

        return static::where('receipt_group_id', $this->receipt_group_id)
            ->where('id', '!=', $this->id)
            ->first();
    }

    /**
     * A Receipt/Tax Invoice is "test" only when EVERY shipment it covers was booked against a
     * Test-mode Agent Account — such documents are safe to fully delete (not just void), which
     * also releases the global shipment<->receipt lock so those shipments can be re-billed
     * fresh. See ReceiptController::destroy().
     */
    public function getIsTestAttribute(): bool
    {
        if (! $this->relationLoaded('shipments')) {
            $this->load('shipments.agentAccount');
        }

        if ($this->shipments->isEmpty()) {
            // A manual-only receipt (see manual_shipment_refs) is a real production document by
            // design, never test/deletable. A GENUINELY orphaned receipt (e.g. leftover dev/test
            // debris from a Shipment record that was itself later deleted) has no manual refs
            // either, so it still falls through to "deletable" here.
            return empty($this->manual_shipment_refs);
        }

        return $this->shipments->every(fn (Shipment $s) => $s->is_test);
    }
}
