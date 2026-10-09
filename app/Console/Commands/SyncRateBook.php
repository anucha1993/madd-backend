<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use App\Models\RateBookRun;
use App\Services\RateBookService;
use App\Support\RateBookSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled every minute (routes/console.php) but self-gates on the Rate Book settings'
 * weekly/monthly day + time, or on a "Sync now" request left by the settings page — so staff
 * control the schedule from the UI and a manual sync never ties up a web request (one run is
 * ~1,000+ rate API calls). Read-only towards the rest of the system: it only asks carriers for
 * rates (never books) and only writes its own rate_book_* rows / rate_book.* settings.
 */
class SyncRateBook extends Command
{
    protected $signature = 'rate-book:sync {--force : Run now regardless of schedule}';

    protected $description = 'Refresh the Rate Book (sell price per weight × zone) from the UPS/DHL rate APIs';

    public function handle(RateBookService $rateBookService): int
    {
        $settings = RateBookSettings::get();
        $requestedBy = IntegrationSetting::get(RateBookSettings::REQUESTED_KEY);
        $manual = $this->option('force') || $requestedBy !== null;

        if (! $manual && ! RateBookSettings::isDue($settings, IntegrationSetting::get(RateBookSettings::LAST_RUN_KEY), now())) {
            return self::SUCCESS;
        }

        RateBookSettings::closeStaleRuns();
        $lock = Cache::lock(RateBookSettings::LOCK, 3 * 3600);
        if (! $lock->get()) {
            return self::SUCCESS; // a run is already in progress
        }

        try {
            IntegrationSetting::set(RateBookSettings::REQUESTED_KEY, null);
            IntegrationSetting::set(RateBookSettings::LAST_RUN_KEY, now()->toIso8601String());

            $run = RateBookRun::create([
                'status' => 'running',
                'trigger' => $manual ? 'manual' : 'schedule',
                'requested_by' => is_numeric($requestedBy) ? (int) $requestedBy : null,
                'settings' => $settings,
                'started_at' => now(),
            ]);

            try {
                $rateBookService->run($run);
            } catch (\Throwable $e) {
                // Kept on the run only — never report() it: that would raise a System Alert, and the
                // Rate Book must not touch anything outside its own tables.
                $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            }

            $this->info("Rate Book run #{$run->id}: {$run->fresh()->status}");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
