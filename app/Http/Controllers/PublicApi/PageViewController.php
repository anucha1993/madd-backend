<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use Illuminate\Http\Request;

/**
 * POST /api/public/v1/web/hit — the WordPress plugins report that someone opened the Tracking /
 * Rate Quote page (navigator.sendBeacon), so the stats can show visitors as well as searches.
 * Logged as endpoint view_tracking / view_rates; nothing is returned.
 */
class PageViewController extends Controller
{
    public function store(Request $request)
    {
        $page = $request->input('page') === 'rates' ? 'rates' : 'tracking';
        ApiRequestLog::create([
            'api_client_id' => $request->attributes->get('api_client')->id,
            'endpoint' => "view_{$page}",
            'ip' => $request->ip(),
            'end_user_ip' => $request->attributes->get('end_user_ip'),
            'status_code' => 204,
        ]);

        return response()->noContent();
    }
}
