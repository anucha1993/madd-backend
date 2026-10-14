<?php

namespace App\Support;

/**
 * UPS accepts no box over 70 kg (actual weight) from Thailand — say so up front, in Thai, instead of
 * sending the request and showing UPS's English refusal.
 */
final class PieceWeightLimit
{
    public const MAX_KG = ['UPS' => WholeKgBilledWeight::MAX_PIECE_KG];

    public static function error(string $carrier, array $packages): ?string
    {
        $max = self::MAX_KG[$carrier] ?? null;
        if ($max === null) {
            return null;
        }
        foreach (array_values($packages) as $i => $pkg) {
            if ((float) ($pkg['weight'] ?? 0) > $max) {
                $n = $i + 1;

                return "{$carrier} รับน้ำหนักจริงไม่เกิน {$max} กก. ต่อกล่อง — กล่องที่ {$n} หนัก {$pkg['weight']} กก. กรุณาแบ่งเป็นหลายกล่อง";
            }
        }

        return null;
    }
}
