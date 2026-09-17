<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSetting;
use App\Models\TrackingSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class TrackingSyncController extends Controller
{
    private const ENABLED_KEY = 'tracking_sync.enabled';
    private const INTERVAL_KEY = 'tracking_sync.interval_minutes';
    private const LAST_RUN_KEY = 'tracking_sync.last_run_at';

    public function showSettings()
    {
        return response()->json([
            'enabled' => IntegrationSetting::get(self::ENABLED_KEY) !== '0',
            'interval_minutes' => (int) (IntegrationSetting::get(self::INTERVAL_KEY) ?: 15),
            'last_run_at' => IntegrationSetting::get(self::LAST_RUN_KEY),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        IntegrationSetting::set(self::ENABLED_KEY, $data['enabled'] ? '1' : '0');
        IntegrationSetting::set(self::INTERVAL_KEY, (string) $data['interval_minutes']);

        return $this->showSettings();
    }

    public function logs(Request $request)
    {
        return response()->json(TrackingSyncLog::latest('started_at')->paginate(20));
    }

    /**
     * Runs the sync command immediately (bypassing the enabled/interval gate) — lets staff
     * verify the feature works right after configuring it, instead of waiting for the next
     * scheduled tick.
     */
    public function runNow()
    {
        Artisan::call('shipments:sync-tracking', ['--force' => true]);

        return response()->json(TrackingSyncLog::latest('started_at')->first());
    }
}
