<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use App\Models\Shipment;
use App\Models\TrackingSyncLog;
use App\Services\DhlTrackingService;
use App\Services\UpsTrackingService;
use Illuminate\Console\Command;

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

    public function handle(UpsTrackingService $upsTrackingService, DhlTrackingService $dhlTrackingService): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            $enabled = IntegrationSetting::get(self::ENABLED_KEY) !== '0';
            if (! $enabled) {
                return self::SUCCESS;
            }

            $intervalMinutes = (int) (IntegrationSetting::get(self::INTERVAL_KEY) ?: 15);
            $lastRunAt = IntegrationSetting::get(self::LAST_RUN_KEY);
            if ($lastRunAt && now()->diffInMinutes($lastRunAt) < $intervalMinutes) {
                return self::SUCCESS;
            }
        }

        $startedAt = now();
        $checked = 0;
        $updated = 0;
        $errors = [];
        $updates = [];

        $shipments = Shipment::with('agentAccount')
            ->where('status', 'booked')
            ->where(function ($q) {
                $q->whereNull('tracking_status')->orWhere('tracking_status', '!=', 'delivered');
            })
            ->whereNotNull('tracking_number')
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

                $newStatus = $this->mapStatus($shipment->carrier, $package['currentStatusCode'] ?? null, $package['currentStatusDescription'] ?? null);
                if ($newStatus === $shipment->tracking_status) {
                    $shipment->update(['tracking_synced_at' => now()]);

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
                    'delivered_at' => $newStatus === 'delivered' ? ($shipment->delivered_at ?? now()) : $shipment->delivered_at,
                ]);
                $updated++;
            } catch (\Throwable $e) {
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

        IntegrationSetting::set(self::LAST_RUN_KEY, now()->toDateTimeString());

        return self::SUCCESS;
    }

    /**
     * Best-effort mapping — carrier status codes/wording vary by product and aren't fully
     * documented, so this leans on UPS's own `currentStatusCode` type where available (D  =
     * Delivered, M = Manifest/label created only) and falls back to keyword matching on the
     * status description for everything else (including all of DHL, which doesn't expose a
     * reliable status code in our normalized response).
     */
    private function mapStatus(string $carrier, ?string $code, ?string $description): string
    {
        if ($carrier === 'UPS' && $code === 'D') {
            return 'delivered';
        }
        if ($carrier === 'UPS' && $code === 'M') {
            return 'not_picked_up';
        }

        $desc = strtolower($description ?? '');
        if ($desc === '') {
            return 'not_picked_up';
        }
        if (str_contains($desc, 'delivered')) {
            return 'delivered';
        }

        return 'in_transit';
    }
}
