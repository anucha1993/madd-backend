<?php

namespace App\Console\Commands;

use App\Mail\ScheduledReportMail;
use App\Models\IntegrationSetting;
use App\Models\Pickup;
use App\Models\SystemAlert;
use App\Services\SmtpSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emails once per pickup when its close time has passed but not every attached shipment has
 * been collected (Pickup::scopeOverdue) — the only signal staff get that the courier never
 * came for an on-call pickup. Goes to whoever scheduled it plus the extra recipients set on
 * the Tracking Sync settings page. Silently does nothing until SMTP is configured/enabled.
 */
class NotifyOverduePickups extends Command
{
    public const RECIPIENTS_KEY = 'pickup_alert.recipients';

    protected $signature = 'pickups:notify-overdue';

    protected $description = 'Email a warning for pickups past their close time that the courier has not fully collected';

    public function handle(SmtpSettingService $smtp): int
    {
        if (! $smtp->isConfigured() || ! $smtp->isEnabled()) {
            return self::SUCCESS;
        }

        $extra = self::extraRecipients();
        $pickups = Pickup::overdue()->whereNull('overdue_notified_at')->with('shipments', 'createdBy', 'agentAccount')->get();
        if ($pickups->isEmpty()) {
            return self::SUCCESS;
        }
        $smtp->applyMailConfig();

        foreach ($pickups as $pickup) {
            $recipients = collect([$pickup->createdBy?->email, ...$extra])
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()->values()->all();
            if (! $recipients) {
                continue;
            }

            $waiting = $pickup->shipments->whereNull('picked_up_at');
            $body = implode("\n", [
                "Pickup {$pickup->carrier} ({$pickup->carrier_reference}) นัดวันที่ {$pickup->pickup_date->format('d/m/Y')} เวลา {$pickup->ready_time}–{$pickup->close_time} เลยเวลานัดแล้ว",
                "แต่ยังมี {$waiting->count()} จาก {$pickup->shipments->count()} Shipment ที่ Courier ยังไม่ได้รับ:",
                '',
                ...$waiting->map(fn ($s) => "- {$s->tracking_number}")->all(),
                '',
                'ที่อยู่รับของ: '.implode(', ', array_filter([$pickup->address['address'] ?? null, $pickup->address['city'] ?? null, $pickup->address['postcode'] ?? null])),
                '',
                "กรุณาติดต่อ {$pickup->carrier} เพื่อติดตาม หรือกด \"ยืนยันรถรับแล้ว\" ในหน้า My Pickups ถ้า Courier มารับแล้วจริง",
            ]);

            try {
                Mail::to($recipients)->send(new ScheduledReportMail(
                    mailSubject: "[MADD] Pickup เลยเวลานัด — {$pickup->carrier} {$pickup->carrier_reference}",
                    bodyText: $body,
                ));
                $pickup->forceFill(['overdue_notified_at' => now()])->save();
            } catch (\Throwable $e) {
                $this->error("Pickup #{$pickup->id}: {$e->getMessage()}");
                SystemAlert::record('pickup_overdue_mail', 'ส่งอีเมลแจ้ง Pickup เลยเวลานัดไม่สำเร็จ', [
                    'pickup_id' => $pickup->id,
                    'carrier_reference' => $pickup->carrier_reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    public static function extraRecipients(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) IntegrationSetting::get(self::RECIPIENTS_KEY)))));
    }
}
