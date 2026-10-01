<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Services\PublicApiStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/public/v1/web/stats — headline usage numbers of the requesting site, for the
 * plugins' [madd_stats] counter (quotes checked, parcels tracked, unique visitors). Only
 * successful searches are counted. Cached 10 minutes; no IPs or details are returned.
 */
class PublicStatsController extends Controller
{
    public function show(Request $request)
    {
        $client = $request->attributes->get('api_client');

        $data = Cache::remember("public-stats:{$client->id}", now()->addMinutes(10), function () use ($client) {
            $logs = fn () => ApiRequestLog::where('api_client_id', $client->id);
            $groups = PublicApiStatsService::GROUPS;
            $views = [...$groups['rates']['views'], ...$groups['tracking']['views']];
            $searches = [...$groups['rates']['searches'], ...$groups['tracking']['searches']];

            return [
                'quotes' => $logs()->whereIn('endpoint', $groups['rates']['searches'])->where('status_code', 200)->where('result_count', '>', 0)->count(),
                'tracked' => $logs()->whereIn('endpoint', $groups['tracking']['searches'])->where('status_code', 200)->count(),
                'visitors' => $logs()->whereIn('endpoint', [...$views, ...$searches])->whereNotNull('end_user_ip')->distinct()->count('end_user_ip'),
                'views' => $logs()->whereIn('endpoint', $views)->count(),
                'since' => optional($logs()->min('created_at'), fn ($d) => substr((string) $d, 0, 10)),
            ];
        });

        return response()->json($data);
    }
}
