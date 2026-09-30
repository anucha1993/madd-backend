<?php

namespace App\Http\Middleware;

use App\Models\Pickup;
use App\Models\Receipt;
use App\Models\Shipment;
use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to every authenticated route: any route-model-bound Shipment/Receipt/Pickup outside
 * the user's data scope (see AccessService::applyScope) is answered with 404, exactly as if it
 * didn't exist — so /shipments/{id}/label etc. can't be reached just by guessing another
 * branch's id. List endpoints apply the same scope to their query themselves.
 */
class EnsureRecordInScope
{
    private const MODULES = [
        Shipment::class => 'shipment',
        Receipt::class => 'receipt',
        Pickup::class => 'pickup',
    ];

    public function __construct(private AccessService $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            $module = is_object($parameter) ? (self::MODULES[$parameter::class] ?? null) : null;
            if ($user && $module && ! $this->access->canSeeRecord($user, $parameter, $module)) {
                return response()->json(['message' => 'ไม่พบข้อมูล', 'error' => 'ไม่พบข้อมูล'], 404);
            }
        }

        return $next($request);
    }
}
