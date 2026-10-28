<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['addon_category_id', 'name', 'carriers', 'customer_types', 'product_types', 'package_type', 'price_type', 'price', 'markup_percent', 'trigger_type', 'always_show', 'status', 'note'])]
class AddonItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'carriers' => 'array',
            'customer_types' => 'array',
            'product_types' => 'array',
            'price' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'always_show' => 'boolean',
            'status' => 'boolean',
        ];
    }

    /**
     * Whether selling this Insurance item means buying the CARRIER's own insurance (UPS Declared
     * Value / DHL II or IB) at booking. Only UPSC is third-party — every other insurance item
     * (ICDV, DHL …) is the carrier's, whatever its price_type: a PERCENT item is just the carrier's
     * insurance sold at our own rate (marked up). Same rule as isThirdPartyInsuranceItem on
     * /shipment/create.
     */
    public function buysCarrierInsurance(): bool
    {
        return ! str_starts_with(strtoupper(trim($this->name)), 'UPSC');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AddonCategory::class, 'addon_category_id');
    }
}
