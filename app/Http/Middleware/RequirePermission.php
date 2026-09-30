<?php

namespace App\Http\Middleware;

use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `perm:shipment.view` — 403 unless the user holds the permission. Several keys
 * (`perm:report.manifest,report.finance`) mean ANY one of them is enough.
 */
class RequirePermission
{
    public function __construct(private AccessService $access)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($permissions as $permission) {
            if ($this->access->can($request->user(), $permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'คุณไม่มีสิทธิ์ใช้งานส่วนนี้', 'error' => 'คุณไม่มีสิทธิ์ใช้งานส่วนนี้'], 403);
    }
}
