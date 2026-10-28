<?php

namespace App\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An over-the-counter box / supply sale (80 mm thermal receipt) — see the create_supply_sales_tables migration. */
#[Fillable(['branch_id', 'sale_no', 'sold_at', 'customer_name', 'customer_address', 'customer_phone', 'payment_method', 'total', 'status', 'voided_at', 'void_note', 'created_by'])]
class SupplySale extends Model
{
    use ScopedByAccess;

    protected static string $accessModule = 'supply_sale';

    protected function casts(): array
    {
        return ['sold_at' => 'datetime', 'voided_at' => 'datetime', 'total' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplySaleLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
