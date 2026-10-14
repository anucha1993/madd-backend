<?php

namespace App\Support;

/**
 * Document shipments have a weight ceiling per carrier (UPS 5 kg, DHL 2 kg) — counted over every
 * document piece in the shipment (weight × quantity). Over it, the carrier is not quoted at Check
 * Rate and the booking is refused. Mirrored in the frontend's DOCUMENT_MAX_KG.
 */
final class DocumentWeightLimit
{
    public const MAX_KG = ['UPS' => 5.0, 'DHL' => 2.0];

    /** Total document weight of a shipment's packages (internal shape: weight / quantity / isDocument). */
    public static function documentWeight(array $packages): float
    {
        return round(collect($packages)
            ->filter(fn ($p) => ! empty($p['isDocument']))
            ->sum(fn ($p) => (float) $p['weight'] * max(1, (int) ($p['quantity'] ?? 1))), 3);
    }

    /** Thai error message when the carrier's document limit is exceeded, else null. */
    public static function error(string $carrier, array $packages): ?string
    {
        $max = self::MAX_KG[$carrier] ?? null;
        $weight = self::documentWeight($packages);
        if ($max === null || $weight <= $max) {
            return null;
        }

        return "{$carrier} ส่งแบบ Document ได้ไม่เกิน {$max} กก. (Document รวม {$weight} กก.)";
    }
}
