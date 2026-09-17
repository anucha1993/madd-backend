<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['started_at', 'finished_at', 'checked_count', 'updated_count', 'error_count', 'errors', 'updates', 'forced'])]
class TrackingSyncLog extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'errors' => 'array',
            'updates' => 'array',
            'forced' => 'boolean',
        ];
    }
}
