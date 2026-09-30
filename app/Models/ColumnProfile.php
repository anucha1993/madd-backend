<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** See the create_column_profiles_table migration / ColumnProfileController. */
#[Fillable(['page_key', 'name', 'columns', 'group_of', 'role_ids', 'sort_order'])]
class ColumnProfile extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'group_of' => 'array',
            'role_ids' => 'array',
        ];
    }

    /** Usable by a user holding any of $roleIds (an empty role_ids list = everyone). */
    public function isAvailableTo(array $roleIds): bool
    {
        return empty($this->role_ids) || (bool) array_intersect($this->role_ids, $roleIds);
    }
}
