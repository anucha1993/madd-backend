<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['weight_markup_band_id', 'scope_type', 'scope_value', 'rate_per_kg', 'formula'])]
class WeightMarkupRate extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['rate_per_kg' => 'float'];
    }
}
