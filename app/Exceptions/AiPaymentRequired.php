<?php

namespace App\Exceptions;

/**
 * The AI provider (DataCorp) refused the request: plan expired, credits used up or a bill is unpaid.
 * $payUrl opens the payment / upgrade page directly (no login needed).
 * Extends RuntimeException so existing `catch (\RuntimeException)` blocks keep working.
 */
class AiPaymentRequired extends \RuntimeException
{
    public function __construct(string $message, public string $reason, public ?string $payUrl = null, int $status = 402)
    {
        parent::__construct($message, $status);
    }

    /** Thai message for the user, by DataCorp error code. */
    public function userMessage(): string
    {
        return [
            'inactive' => 'แพ็กเกจ AI หมดอายุแล้ว',
            'quota_exceeded' => 'Credits ของแพ็กเกจ AI ใช้ครบแล้ว',
            'payment_required' => 'มียอดค้างชำระค่าบริการ AI',
        ][$this->reason] ?? 'ต้องชำระค่าบริการ AI ก่อนใช้งานต่อ';
    }

    /** Throw if a DataCorp error response carries a payment link (error.action_url). */
    public static function throwIfPaymentLink(\Illuminate\Http\Client\Response $response): void
    {
        if ($payUrl = $response->json('error.action_url')) {
            throw new self(explode(' — ', (string) $response->json('error.message'))[0], (string) $response->json('error.code'), $payUrl, $response->status());
        }
    }
}
