<?php

namespace App\Models\Concerns;

use App\Services\AccessService;

/**
 * Strips the field groups the CURRENT user may not see (config/permissions.php `fields`) from
 * this model's JSON output — everywhere it's serialized, including when nested in another
 * model's response (e.g. a Receipt's shipments). Only serialization is affected: attributes stay
 * readable in PHP, so internal logic (waybill building, reports, services) is unchanged.
 * No authenticated user (console/queue) = nothing hidden.
 *
 * The using model declares `protected static string $accessModule`.
 */
trait HidesRestrictedFields
{
    protected static function bootHidesRestrictedFields(): void
    {
        $apply = function ($model) {
            $user = auth()->user();
            if ($user) {
                $model->makeHidden(app(AccessService::class)->hiddenColumns($user, static::$accessModule));
            }
        };

        static::retrieved($apply);
        static::created($apply);
    }
}
