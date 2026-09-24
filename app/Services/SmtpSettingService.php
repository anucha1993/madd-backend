<?php

namespace App\Services;

use App\Mail\ScheduledReportMail;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Gmail-only SMTP settings for the scheduled report sender (see
 * App\Console\Commands\SendScheduledReports) — deliberately not a generic "any SMTP host" form,
 * host/port/encryption are always smtp.gmail.com:587/tls. The app password is encrypted at rest
 * (Crypt::encryptString) since it's a real credential, unlike the plain-text convention used by
 * R2/AgentAccount settings elsewhere in this app.
 */
class SmtpSettingService
{
    private const ADDRESS_KEY = 'smtp.gmail_address';
    private const APP_PASSWORD_KEY = 'smtp.gmail_app_password';
    private const ENABLED_KEY = 'smtp.enabled';

    public function getAddress(): ?string
    {
        return IntegrationSetting::get(self::ADDRESS_KEY);
    }

    public function isEnabled(): bool
    {
        return IntegrationSetting::get(self::ENABLED_KEY) === '1';
    }

    public function hasAppPassword(): bool
    {
        return (bool) IntegrationSetting::get(self::APP_PASSWORD_KEY);
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->getAddress() && $this->hasAppPassword());
    }

    public function updateSettings(string $address, ?string $appPassword, bool $enabled): void
    {
        IntegrationSetting::set(self::ADDRESS_KEY, $address);
        if ($appPassword !== null) {
            IntegrationSetting::set(self::APP_PASSWORD_KEY, Crypt::encryptString($appPassword));
        }
        IntegrationSetting::set(self::ENABLED_KEY, $enabled ? '1' : '0');
    }

    private function decryptedAppPassword(): ?string
    {
        $encrypted = IntegrationSetting::get(self::APP_PASSWORD_KEY);
        if (! $encrypted) {
            return null;
        }

        return Crypt::decryptString($encrypted);
    }

    /**
     * Points Laravel's 'smtp' mailer at Gmail with the saved credentials for the rest of this
     * request/process — rebuilt fresh every call (no config cache) so settings changes take
     * effect immediately, same reasoning as R2Service::disk().
     */
    public function applyMailConfig(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gmail SMTP is not configured yet.');
        }

        $address = $this->getAddress();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
            'mail.mailers.smtp.username' => $address,
            'mail.mailers.smtp.password' => $this->decryptedAppPassword(),
            'mail.from.address' => $address,
            'mail.from.name' => config('app.name'),
        ]);
    }

    public function sendTest(string $to): void
    {
        $this->applyMailConfig();

        Mail::to($to)->send(new ScheduledReportMail(
            mailSubject: 'MADD — Test Email (SMTP Settings)',
            bodyText: 'อีเมลนี้ถูกส่งเพื่อทดสอบการตั้งค่า SMTP Gmail — หากคุณได้รับอีเมลนี้ แสดงว่าการตั้งค่าถูกต้อง.',
        ));
    }
}
