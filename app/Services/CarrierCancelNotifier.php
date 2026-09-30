<?php

namespace App\Services;

use App\Mail\ScheduledReportMail;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * DHL Express has no cancel API (every POST/PATCH/PUT/DELETE on /shipments/{tn} answers 405),
 * so a voided DHL waybill is cancelled by asking DHL: this emails the booking account's DHL
 * contact(s) (AgentAccount.cancel_notify_emails), CC the person who voided it, and records the
 * request on the shipment. The shipment stays carrier_cancel_status 'pending' until staff
 * confirm DHL's reply (ShipmentController::confirmCarrierCancel).
 */
class CarrierCancelNotifier
{
    public function __construct(private SmtpSettingService $smtp)
    {
    }

    /**
     * @return array{sent: bool, to: array<int,string>, message: string}
     */
    public function requestCancellation(Shipment $shipment, ?User $requestedBy): array
    {
        $shipment->loadMissing('agentAccount');
        $to = self::parseEmails($shipment->agentAccount?->cancel_notify_emails);

        if (! $to) {
            return ['sent' => false, 'to' => [], 'message' => 'ยังไม่ได้ตั้งอีเมลผู้ติดต่อ DHL ของบัญชีนี้ (หน้า Agent Accounts) — กรุณาคัดลอกข้อความแล้วแจ้ง DHL เอง'];
        }
        if (! $this->smtp->isConfigured() || ! $this->smtp->isEnabled()) {
            return ['sent' => false, 'to' => $to, 'message' => 'ยังไม่ได้ตั้งค่า / เปิดใช้ SMTP — กรุณาคัดลอกข้อความแล้วแจ้ง DHL เอง'];
        }

        try {
            $this->smtp->applyMailConfig();
            $mail = Mail::to($to);
            if ($requestedBy?->email && filter_var($requestedBy->email, FILTER_VALIDATE_EMAIL)) {
                $mail->cc($requestedBy->email);
            }
            $mail->send(new ScheduledReportMail(
                mailSubject: "ขอยกเลิก Waybill {$shipment->tracking_number} / Request to cancel waybill {$shipment->tracking_number}",
                bodyText: self::messageFor($shipment, $requestedBy),
            ));
        } catch (\Throwable $e) {
            report($e);

            return ['sent' => false, 'to' => $to, 'message' => "ส่งอีเมลแจ้ง DHL ไม่สำเร็จ ({$e->getMessage()}) — กรุณาคัดลอกข้อความแล้วแจ้ง DHL เอง"];
        }

        $shipment->update(['carrier_cancel_requested_at' => now(), 'carrier_cancel_requested_to' => implode(', ', $to)]);

        return ['sent' => true, 'to' => $to, 'message' => 'ส่งอีเมลขอยกเลิกถึง DHL แล้ว: '.implode(', ', $to)];
    }

    public static function messageFor(Shipment $shipment, ?User $requestedBy = null): string
    {
        $booked = $shipment->created_at?->timezone('Asia/Bangkok')->format('d/m/Y');
        $account = $shipment->agentAccount?->username_acc ?? '-';

        return implode("\n", array_filter([
            'เรียน DHL Express',
            '',
            "ขอยกเลิก Waybill เลขที่ {$shipment->tracking_number} ซึ่งจองผ่าน MyDHL API ภายใต้บัญชี {$account} เมื่อวันที่ {$booked}",
            'พัสดุยังไม่ได้ส่งมอบให้ Courier และยังไม่มีการ scan',
            $shipment->void_reason ? "เหตุผล: {$shipment->void_reason}" : null,
            'รบกวนยืนยันการยกเลิก และแจ้งว่ามีค่าใช้จ่ายหรือค่าชดเชยหรือไม่',
            '',
            "Please cancel waybill {$shipment->tracking_number} (account {$account}, booked {$booked} via MyDHL API). The shipment has not been tendered or scanned. Kindly confirm the cancellation and any charges.",
            '',
            $requestedBy ? "ผู้ขอยกเลิก / Requested by: {$requestedBy->name}" : null,
        ], fn ($line) => $line !== null));
    }

    /** @return array<int, string> */
    public static function parseEmails(?string $list): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,;\s]+/', (string) $list) ?: []),
            fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL),
        )));
    }
}
