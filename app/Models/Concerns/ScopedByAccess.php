<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\AccessService;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * `Model::visibleTo($user)` — limits a query to the records this user's data scope allows
 * (own / branch / all, see AccessService::applyScope). The using model declares
 * `protected static string $accessModule`.
 */
trait ScopedByAccess
{
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $user ? app(AccessService::class)->applyScope($query, $user, static::$accessModule) : $query;
    }
}
