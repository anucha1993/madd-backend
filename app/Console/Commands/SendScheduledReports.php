<?php

namespace App\Console\Commands;

use App\Http\Controllers\ManifestReportController;
use App\Mail\ScheduledReportMail;
use App\Models\ReportSchedule;
use App\Services\SmtpSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled once per minute (see routes/console.php) but self-gates per schedule via isDue() —
 * same "every minute, self-gated" pattern as SyncShipmentTracking. Only the 'manifest' report
 * type is implemented today (ReportSchedule::report_type is a plain string for future growth).
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled
        {--force : Ignore the due date/time gate and send immediately}
        {--only= : Only process the ReportSchedule with this id}';

    protected $description = 'Email scheduled Manifest reports (daily/weekly/monthly) via the configured Gmail SMTP account';

    public function handle(ManifestReportController $manifestReportController, SmtpSettingService $smtpSettingService): int
    {
        if (! $smtpSettingService->isConfigured() || ! $smtpSettingService->isEnabled()) {
            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $onlyId = $this->option('only');

        $query = ReportSchedule::where('is_active', true);
        if ($onlyId) {
            $query->where('id', $onlyId);
        }

        foreach ($query->get() as $schedule) {
            if (! $force && ! $this->isDue($schedule)) {
                continue;
            }

            try {
                $filters = [
                    'range' => $schedule->report_range,
                    'branch_id' => $schedule->branch_id,
                    'carrier' => $schedule->carrier,
                    'agent_account_id' => $schedule->agent_account_id,
                ];
                $content = $manifestReportController->buildManifestXlsx($filters);
                $filename = 'manifest-'.now()->format('Ymd-His').'.xlsx';

                $smtpSettingService->applyMailConfig();
                Mail::to($schedule->recipients)->send(new ScheduledReportMail(
                    mailSubject: "MADD — {$schedule->name} (".now()->format('d/m/Y').')',
                    bodyText: "แนบไฟล์ Manifest Report \"{$schedule->name}\" ประจำวันที่ ".now()->format('d/m/Y')." ตามที่ตั้งเวลาไว้ในระบบ",
                    attachmentContent: $content,
                    attachmentName: $filename,
                ));

                $schedule->update(['last_sent_at' => now(), 'last_sent_status' => 'success', 'last_error' => null]);
            } catch (\Throwable $e) {
                // One schedule failing (bad recipient, SMTP hiccup, etc.) must never stop the
                // rest from sending — same isolation approach as SyncShipmentTracking's per-shipment try/catch.
                $schedule->update(['last_sent_at' => now(), 'last_sent_status' => 'failed', 'last_error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }

    /** Fires once per day at the configured send_time — last_sent_at prevents a same-day resend even though the scheduler ticks every minute. */
    private function isDue(ReportSchedule $schedule): bool
    {
        $now = now();
        if ($schedule->last_sent_at && $schedule->last_sent_at->isSameDay($now)) {
            return false;
        }

        if ($now->format('H:i') !== $schedule->send_time) {
            return false;
        }

        return match ($schedule->frequency) {
            'daily' => true,
            'weekly' => $now->dayOfWeek === $schedule->day_of_week,
            'monthly' => $now->day === $schedule->day_of_month,
            default => false,
        };
    }
}
