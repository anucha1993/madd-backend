<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A configurable Role — what it can grant is defined in config/permissions.php, see
 * AccessService for how a user's Roles combine.
 */
#[Fillable(['key', 'name', 'description', 'is_super_admin', 'permissions', 'field_access', 'data_scopes'])]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
            'is_system' => 'boolean',
            'permissions' => 'array',
            'field_access' => 'array',
            'data_scopes' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
