<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'company_name', 'tax_id', 'phone', 'email', 'notes'])]
class Customer extends Model
{
    use HasFactory;

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    // The customer's contact/company/tax/phone/email are only ever meant to preview the
    // FIRST saved address (see CustomerAddressManager on the frontend) — never edited
    // separately — so this mirrors the same ordering as CustomerAddressController::index
    // (default address first, then alphabetical by label) to stay consistent everywhere.
    public function primaryAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->orderByDesc('is_default')->orderBy('label');
    }
}
