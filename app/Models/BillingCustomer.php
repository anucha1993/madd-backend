<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id', 'name', 'tax_id', 'is_head_office', 'branch_no',
    'address1', 'address2', 'address3', 'city', 'state_code', 'postcode', 'country',
    'phone', 'email', 'notes',
])]
class BillingCustomer extends Model
{
    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
