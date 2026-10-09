<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'trigger', 'requested_by', 'total_points', 'done_points', 'error_points', 'error', 'settings', 'started_at', 'finished_at'])]
class RateBookRun extends Model
{
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(RateBookRow::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
