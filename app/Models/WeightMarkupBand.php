<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['carrier', 'package_type', 'agent_account_id', 'min_weight', 'max_weight', 'sort_order'])]
class WeightMarkupBand extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['min_weight' => 'float', 'max_weight' => 'float'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(WeightMarkupRate::class);
    }
}
