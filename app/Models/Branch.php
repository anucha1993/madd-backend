<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'company_name', 'code', 'tax_id', 'address', 'phone', 'status'])]
class Branch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function carrierAccounts(): HasMany
    {
        return $this->hasMany(BranchCarrierAccount::class);
    }
}
