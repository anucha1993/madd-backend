<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\HandleCors as BaseHandleCors;
use Illuminate\Http\Request;

/**
 * App-wide CORS (config/cors.php — the MADD frontend's origin) except the browser Tracking API,
 * whose allowed origins are per API client and set by AuthenticateWebOrigin.
 */
class HandleCors extends BaseHandleCors
{
    protected function hasMatchingPath(Request $request): bool
    {
        return ! $request->is('api/public/v1/web/*') && parent::hasMatchingPath($request);
    }
}
