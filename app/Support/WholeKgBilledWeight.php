<?php

namespace App\Support;

/**
 * Over 10 kg we only sell whole kilograms (UPS and DHL alike): a 10.1 kg shipment is quoted as
 * 11 kg, even though the carrier itself would price 10.5. So the packages sent to the rate API are
 * reshaped so the carrier's billed weight comes out at the next whole kg. UPS and DHL bill each box
 * at max(actual, L×W×H / 5000) rounded up to 0.5 kg, so:
 *
 *  - a box whose actual weight decides is sent at that rounded weight;
 *  - a box whose dimensions decide is sent as it is (its actual weight must stay real — UPS refuses
 *    any box over 70 kg ACTUAL, however light the volumetric weight makes it look);
 *  - when the total ends in .5, one box gets +0.5 kg (split out of its row if the row has several
 *    boxes) — as actual weight while that stays within 70 kg, else as a slightly longer box.
 *
 * Only for quoting — the real booking still sends the weights staff entered.
 */
final class WholeKgBilledWeight
{
    public const FROM_KG = 10.0;

    // Heaviest box (actual weight) UPS accepts — never push a box past it to round the total.
    public const MAX_PIECE_KG = 70.0;

    private const VOLUMETRIC_DIVISOR = 5000;

    /** The weight the carrier will bill for these packages once quoted with forQuote() — e.g. 10.1 kg → 11. */
    public static function billedWeight(array $packages): float
    {
        $total = self::total(array_values($packages));

        return $total <= self::FROM_KG ? $total : ceil($total);
    }

    /** Packages (internal shape: weight / length / width / height / quantity / declaredValue) to quote with. */
    public static function forQuote(array $packages): array
    {
        $rows = array_values($packages);
        $total = self::total($rows);
        if ($total <= self::FROM_KG) {
            return $packages;
        }

        // Actual weight decides → send it rounded; dimensions decide → leave the box as entered.
        foreach ($rows as $i => $row) {
            if ((float) $row['weight'] >= self::volumetric($row)) {
                $rows[$i]['weight'] = self::billed($row);
            }
        }
        if (fmod($total, 1.0) == 0.0) {
            return $rows;
        }

        // Total ends in .5 → one box +0.5 kg: a single-box row first, one that stays within 70 kg.
        $order = collect($rows)->keys()->sortBy(fn ($i) => [self::billed($rows[$i]) + 0.5 > self::MAX_PIECE_KG ? 1 : 0, self::quantity($rows[$i]) === 1 ? 0 : 1])->values();
        $index = $order->first();
        if (self::quantity($rows[$index]) > 1) {
            $row = $rows[$index];
            $quantity = self::quantity($row);
            $declared = isset($row['declaredValue']) ? (float) $row['declaredValue'] : null; // row total
            $rows[$index] = ['quantity' => $quantity - 1, 'declaredValue' => $declared === null ? null : round($declared * ($quantity - 1) / $quantity, 2)] + $row;
            $rows[] = ['quantity' => 1, 'declaredValue' => $declared === null ? null : round($declared / $quantity, 2)] + $row;
            $index = count($rows) - 1;
        }

        $target = self::billed($rows[$index]) + 0.5;
        // A box already over 70 kg actual (carriers that take it) just gets heavier — only a box that
        // must stay within 70 kg actual grows in size instead.
        if ($target <= self::MAX_PIECE_KG || (float) $rows[$index]['weight'] > self::MAX_PIECE_KG || ! self::volumetric($rows[$index])) {
            $rows[$index]['weight'] = $target;
        } elseif ($dims = self::grownDimensions($rows[$index], $target)) {
            // Every box is near 70 kg: make this one slightly bigger so its volumetric weight is the target.
            $rows[$index] = $dims + $rows[$index];
        }
        // else: no whole-cm size lands on the target — the carrier's own .5 kg stays (rare).

        return $rows;
    }

    /**
     * The smallest whole-cm growth of a box (UPS rounds each side up to a whole cm) whose
     * volumetric weight, rounded up to 0.5 kg, is exactly $target.
     *
     * @return array{length: int, width: int, height: int}|null
     */
    private static function grownDimensions(array $row, float $target): ?array
    {
        [$l, $w, $h] = [(int) ceil((float) $row['length']), (int) ceil((float) $row['width']), (int) ceil((float) $row['height'])];
        $best = null;
        foreach (range(0, 5) as $dl) {
            foreach (range(0, 5) as $dw) {
                foreach (range(0, 5) as $dh) {
                    $volume = ($l + $dl) * ($w + $dw) * ($h + $dh);
                    if (ceil(round($volume / self::VOLUMETRIC_DIVISOR * 2, 6)) / 2 === $target && ($best === null || $volume < $best[0])) {
                        $best = [$volume, ['length' => $l + $dl, 'width' => $w + $dw, 'height' => $h + $dh]];
                    }
                }
            }
        }

        return $best[1] ?? null;
    }

    private static function total(array $rows): float
    {
        return array_sum(array_map(fn ($row) => self::billed($row) * self::quantity($row), $rows));
    }

    /** One box's billed weight: max(actual, volumetric), rounded up to 0.5 kg. */
    private static function billed(array $row): float
    {
        return ceil(round(max((float) $row['weight'], self::volumetric($row)) * 2, 6)) / 2;
    }

    private static function volumetric(array $row): float
    {
        return ! empty($row['length']) && ! empty($row['width']) && ! empty($row['height'])
            ? (float) $row['length'] * (float) $row['width'] * (float) $row['height'] / self::VOLUMETRIC_DIVISOR
            : 0.0;
    }

    private static function quantity(array $row): int
    {
        return max(1, (int) ($row['quantity'] ?? 1));
    }
}
