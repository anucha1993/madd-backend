<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One weight band of the Heavy Weight sell rates — rates = THB per kg by "all" / "zone:N". */
#[Fillable(['carrier', 'min_weight', 'max_weight', 'rates', 'sort_order'])]
class HeavyRateBand extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['min_weight' => 'float', 'max_weight' => 'float', 'rates' => 'array'];
    }
}
