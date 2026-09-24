<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs every minute but self-gates on the configured interval (see SyncShipmentTracking) —
// this is what makes the sync interval "configurable via UI" without editing this cron entry.
Schedule::command('shipments:sync-tracking')->everyMinute();

// Runs every minute but self-gates per-schedule on its own day/time (see SendScheduledReports).
Schedule::command('reports:send-scheduled')->everyMinute();
