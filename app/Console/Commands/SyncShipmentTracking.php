<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use App\Models\Shipment;
use App\Models\SystemAlert;
use App\Models\TrackingSyncLog;
use App\Services\DhlTrackingService;
use App\Services\TrackingStatusClassifier;
use App\Services\UpsTrackingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Periodically syncs the carrier's REAL delivery progress into `shipments.tracking_status`
 * (separate from `status`, which only reflects our own booking lifecycle) — a 3-state model:
 * not_picked_up -> in_transit -> delivered. Scheduled to run every minute (see routes/console.php)
 * but self-gates on the configured interval (IntegrationSetting 'tracking_sync.interval_minutes')
 * so it doesn't hammer carrier APIs — this is what makes the interval "configurable via UI"
 * without needing to edit any cron expression.
 */
class SyncShipmentTracking extends Command
{
    protected $signature = 'shipments:sync-tracking {--force : Ignore the configured interval/enabled setting and run now}';

    protected $description = 'Sync UPS/DHL tracking status (not picked up / in transit / delivered) into booked shipments';

    private const ENABLED_KEY = 'tracking_sync.enabled';
    private const INTERVAL_KEY = 'tracking_sync.interval_minutes';
    private const LAST_RUN_KEY = 'tracking_sync.last_run_at';

    private const MAX_AGE_DAYS = 60;

    private const MAX_PER_RUN = 200;

    public function handle(UpsTrackingService $upsTrackingService, DhlTrackingService $dhlTrackingService, TrackingStatusClassifier $classifier): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            $enabled = IntegrationSetting::get(self::ENABLED_KEY) !== '0';
            if (! $enabled) {
                return self::SUCCESS;
            }

            $intervalMinutes = (int) (IntegrationSetting::get(self::INTERVAL_KEY) ?: 15);
            $lastRunAt = IntegrationSetting::get(self::LAST_RUN_KEY);
            // Carbon 3 diffs are signed: now()->diffInMinutes($past) is NEGATIVE, which made this
            // check always true and silently stopped every scheduled run after the first.
            if ($lastRunAt && Carbon::parse($lastRunAt)->diffInMinutes(now(), absolute: true) < $intervalMinutes) {
                return self::SUCCESS;
            }
        }

        $startedAt = now();
        $checked = 0;
        $updated = 0;
        $errors = [];
        $updates = [];

        // Bounded per run so a growing backlog can't make one run hammer the carrier APIs (or
        // outlast the next scheduled tick): shipments older than MAX_AGE_DAYS are given up on,
        // and the least-recently-synced (never-synced first) go first, so every shipment still
        // gets its turn across consecutive runs.
        $shipments = Shipment::with('agentAccount')
            ->where('status', 'booked')
            ->where(function ($q) {
                $q->whereNull('tracking_status')->orWhere('tracking_status', '!=', 'delivered');
            })
            ->whereNotNull('tracking_number')
            ->where('created_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
            ->orderByRaw('tracking_synced_at IS NOT NULL')
            ->orderBy('tracking_synced_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($shipments as $shipment) {
            $checked++;
            $account = $shipment->agentAccount;
            if (! $account) {
                continue;
            }

            try {
                if ($shipment->carrier === 'UPS') {
                    $result = $upsTrackingService->trackByInquiry($account->client_id, $account->client_secret, $shipment->tracking_number, [], $account->mode);
                } else {
                    $result = $dhlTrackingService->trackByNumber($account->basic_auth_username, $account->basic_auth_password, $shipment->tracking_number, $account->mode);
                }

                $package = $result['packages'][0] ?? null;
                if (! $package) {
                    continue;
                }

                $progress = $classifier->classify($shipment->carrier, $package);
                $newStatus = $progress['status'];
                // Never walk a shipment back to "not picked up" — a staff confirmation (or an
                // earlier scan) already proved collection even if the carrier's feed lags.
                if ($newStatus === 'not_picked_up' && $shipment->picked_up_at) {
                    $newStatus = $shipment->tracking_status ?: 'in_transit';
                }
                // The carrier's own scan is the authoritative collection time — it replaces a
                // manual confirmation's timestamp once it arrives.
                $pickupUpdate = [];
                if ($newStatus !== 'not_picked_up' && $shipment->picked_up_source !== 'carrier' && $progress['picked_up_at']) {
                    $pickupUpdate = ['picked_up_at' => $progress['picked_up_at'], 'picked_up_source' => 'carrier'];
                } elseif ($newStatus !== 'not_picked_up' && ! $shipment->picked_up_at) {
                    $pickupUpdate = ['picked_up_at' => now(), 'picked_up_source' => 'carrier'];
                }

                if ($newStatus === $shipment->tracking_status) {
                    $shipment->update(['tracking_synced_at' => now()] + $pickupUpdate);

                    continue;
                }

                $updates[] = [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier,
                    'from' => $shipment->tracking_status,
                    'to' => $newStatus,
                ];

                $shipment->update([
                    'tracking_status' => $newStatus,
                    'tracking_raw_status' => $package['currentStatusDescription'] ?? null,
                    'tracking_synced_at' => now(),
                    'delivered_at' => $newStatus === 'delivered' ? ($shipment->delivered_at ?? $progress['delivered_at'] ?? now()) : $shipment->delivered_at,
                ] + $pickupUpdate);
                $updated++;
            } catch (\Throwable $e) {
                // 404 = label created but not scanned by the carrier yet — not a sync failure.
                if ($e->getCode() === 404) {
                    $shipment->update(['tracking_synced_at' => now()]);

                    continue;
                }
                $errors[] = ['shipment_id' => $shipment->id, 'tracking_number' => $shipment->tracking_number, 'message' => $e->getMessage()];
            }
        }

        TrackingSyncLog::create([
            'started_at' => $startedAt,
            'finished_at' => now(),
            'checked_count' => $checked,
            'updated_count' => $updated,
            'error_count' => count($errors),
            'errors' => $errors ?: null,
            'updates' => $updates ?: null,
            'forced' => $force,
        ]);

        if ($errors) {
            // Fixed message so repeated failing runs fold into one open alert.
            SystemAlert::record('tracking_sync', 'Tracking sync: บาง Shipment ดึงสถานะไม่สำเร็จ', [
                'error_count' => count($errors),
                'checked_count' => $checked,
                'errors' => array_slice($errors, 0, 20),
            ], 'warning');
        }

        IntegrationSetting::set(self::LAST_RUN_KEY, now()->toDateTimeString());

        return self::SUCCESS;
    }
}
