<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'document_type', 'field', 'pattern', 'next_number'])]
class DocumentNumberSequence extends Model
{
    use Auditable;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
