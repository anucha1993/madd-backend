<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The customer-facing rate-card sheets of the business's rate files (madd/SAMPLE), filled with
 * the system's selling price — exactly what /shipment/create sells at:
 *
 *  UPS "SAVE" — "UPS Regular Express Saver": Weight / Zone × 1 2 JP AU 3 4 5 USA PR 6 7 8 9,
 *        page by page (0.5–10 · –24 · 25–45 · 46–70 · per-kg bands), with the AHC / remote notes.
 *  UPS "DOC"  — "UPS-DOCUMENT": envelope + documents by zone, then the COUNTRY list.
 *  DHL "SELLING" — Shipment Weight(Kg) × Zone 1 … Zone 9 (+ AU NZ), shaded every other column.
 *  DHL "DOC"  — "DHL - DOCUMENT RATE": documents by zone, then the COUNTRY list.
 */
class RateBookRateCard
{
    // Per-kg bands are listed kg by kg (rate × kg) up to this weight, beyond it as a per-kg rate.
    public const EXPAND_PER_KG_UP_TO = 70;

    private const GREY = 'D9D9D9';

    /**
     * Ordered lines of one package type: step weights, per-kg bands kg by kg (≤ 70), and per-kg
     * rate rows past that. Each line: label, kg (number shown), multiplier and the source point.
     *
     * @return list<array{label:string|float, kg:float, mult:float, source:string, rate:bool}>
     */
    public static function lines(Collection $typeRows): array
    {
        $lines = [];
        foreach ($typeRows->unique(fn ($r) => $r->band_label.'|'.$r->weight) as $point) {
            $source = $point->band_label.'|'.$point->weight;
            if (! $point->is_per_kg) {
                $lines[] = ['label' => $point->weight, 'kg' => $point->weight, 'mult' => 1.0, 'source' => $source, 'rate' => false];

                continue;
            }
            [$from, $to] = self::bandRange($point->band_label);
            $first = (int) floor($from) + 1;
            $last = min($to ?? self::EXPAND_PER_KG_UP_TO, self::EXPAND_PER_KG_UP_TO);
            for ($kg = $first; $kg <= $last; $kg++) {
                $lines[] = ['label' => (float) $kg, 'kg' => (float) $kg, 'mult' => (float) $kg, 'source' => $source, 'rate' => false];
            }
            if ($to === null || $to > self::EXPAND_PER_KG_UP_TO) {
                $start = max($first, self::EXPAND_PER_KG_UP_TO + 1);
                $lines[] = ['label' => $to === null ? "{$start} and above" : $start.'-'.(int) $to, 'kg' => (float) $start, 'mult' => 1.0, 'source' => $source, 'rate' => true];
            }
        }

        return $lines;
    }

    // ---- UPS SAVE ----------------------------------------------------------------------------

    public static function upsSave(Worksheet $sheet, Collection $rows, array $columns, string $note): void
    {
        $sheet->setTitle('SAVE');
        $box = $rows->where('package_type', 'box');
        $lastCol = Coordinate::stringFromColumnIndex(1 + count($columns));
        $lines = self::lines($box);
        // The rate file's pages.
        $pages = [
            ['title' => 'UPS Regular Express Saver', 'test' => fn ($l) => ! $l['rate'] && $l['kg'] <= 10],
            ['title' => null, 'test' => fn ($l) => ! $l['rate'] && $l['kg'] > 10 && $l['kg'] <= 24, 'after' => 'See Next Page for  25 Kg up'],
            ['title' => 'Saver', 'test' => fn ($l) => ! $l['rate'] && $l['kg'] > 24 && $l['kg'] <= 45],
            ['title' => 'Saver', 'test' => fn ($l) => ! $l['rate'] && $l['kg'] > 45],
            ['title' => 'Saver', 'test' => fn ($l) => $l['rate']],
        ];

        $r = 2;
        foreach ($pages as $page) {
            $pageLines = array_values(array_filter($lines, $page['test']));
            if (! $pageLines) {
                continue;
            }
            // Title bar: name centred, carrier at the right edge.
            if ($page['title']) {
                $sheet->setCellValue("B{$r}", $page['title']);
                $sheet->mergeCells('B'.$r.':'.Coordinate::stringFromColumnIndex(count($columns))."{$r}");
                $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $sheet->setCellValue("{$lastCol}{$r}", 'UPS');
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true)->setSize(18);
            $r++;

            self::upsHeader($sheet, $r, $columns, $lastCol);
            $r += 2;
            $start = $r;
            foreach ($pageLines as $line) {
                if (! $line['rate'] && $line['kg'] == 31) {
                    $sheet->setCellValue("A{$r}", '**for each box weighing 31 kgs and up , 1,200 THB is applied for  AHC ( Additional Handling Charge ) ');
                    $sheet->getStyle("A{$r}")->getFont()->setBold(true);
                    $r++;
                }
                $sheet->setCellValue("A{$r}", $line['label']);
                self::priceCells($sheet, $r, 2, $columns, $box, $line);
                if (! $line['rate'] && $line['kg'] == 5) {
                    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
                }
                $r++;
            }
            self::grid($sheet, "A{$start}:{$lastCol}".($r - 1));
            $sheet->getStyle("A{$start}:A".($r - 1))->getNumberFormat()->setFormatCode('0.0');
            $sheet->getStyle("B{$start}:{$lastCol}".($r - 1))->getNumberFormat()->setFormatCode('#,##0');
            self::shadeExtras($sheet, $start, $r - 1, 2, $columns);
            $sheet->getStyle("A".($r - 1).":{$lastCol}".($r - 1))->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
            if (! empty($page['after'])) {
                $r++;
                $sheet->setCellValue("A{$r}", $page['after']);
                $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            }
            $r += 3;
        }
        $sheet->setCellValue("A{$r}", '*800 THB or 30 THB / Kg may be charged for Remoted Area Depending on Destination Postal code or city');
        $sheet->setCellValue('A'.($r + 1), $note);
        $sheet->getStyle('A'.($r + 1))->getFont()->setSize(8)->getColor()->setRGB('808080');

        $sheet->getColumnDimension('A')->setWidth(10);
        for ($c = 2; $c <= 1 + count($columns); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(9.5);
        }
    }

    private static function upsHeader(Worksheet $sheet, int $r, array $columns, string $lastCol): void
    {
        $sheet->setCellValue("A{$r}", "Weight /\n Zone");
        $sheet->mergeCells("A{$r}:A".($r + 1));
        foreach ($columns as $i => $column) {
            $col = Coordinate::stringFromColumnIndex(2 + $i);
            $sheet->setCellValue("{$col}{$r}", $column['extra'] ? str_replace(' ', "\n", $column['label']) : (int) $column['key']);
            $sheet->mergeCells("{$col}{$r}:{$col}".($r + 1));
        }
        $range = "A{$r}:{$lastCol}".($r + 1);
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->setSize(14);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A{$r}")->getFont()->setSize(9);
        self::fill($sheet, $range, self::GREY);
        self::grid($sheet, $range);
    }

    // ---- UPS DOC / DHL DOC ---------------------------------------------------------------------

    public static function upsDoc(Worksheet $sheet, Collection $rows, array $columns, string $note, string $year): void
    {
        $sheet->setTitle('DOC');
        $zones = array_values(array_filter($columns, fn ($c) => ! $c['extra']));
        $doc = $rows->where('package_type', 'document');
        $lastCol = Coordinate::stringFromColumnIndex(1 + count($zones));

        $sheet->setCellValue('A1', "UPS-DOCUMENT  {$year}");
        $sheet->mergeCells("A1:{$lastCol}3");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->setCellValue('A5', "Shipment\nweight (kg)");
        foreach ($zones as $i => $column) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(2 + $i).'5', "Zone\n{$column['key']}");
        }
        self::docHeaderStyle($sheet, "A5:{$lastCol}5", self::GREY);

        $lines = self::lines($doc);
        $r = 6;
        $start = $r;
        if ($lines) {
            $sheet->setCellValue("A{$r}", 'UPS Express Saver Envelope');
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
            $sheet->setCellValue("A{$r}", $lines[0]['label']);
            self::priceCells($sheet, $r, 2, $zones, $doc, $lines[0]);
            $r++;
            $sheet->setCellValue("A{$r}", 'UPS Express Saver Documents');
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
            foreach ($lines as $line) {
                $sheet->setCellValue("A{$r}", $line['label']);
                self::priceCells($sheet, $r, 2, $zones, $doc, $line);
                $r++;
            }
        }
        self::grid($sheet, "A{$start}:{$lastCol}".($r - 1));
        $sheet->getStyle("B{$start}:{$lastCol}".($r - 1))->getNumberFormat()->setFormatCode('#,##0');
        $r = self::countryBlock($sheet, $r, $zones, 'UPS', false);
        $sheet->setCellValue('A'.($r + 1), $note);
        $sheet->getStyle('A'.($r + 1))->getFont()->setSize(8)->getColor()->setRGB('808080');
        self::widths($sheet, count($zones), 13);
    }

    public static function dhlDoc(Worksheet $sheet, Collection $rows, array $columns, string $note, string $year): void
    {
        $sheet->setTitle('DOC');
        $doc = $rows->where('package_type', 'document');
        $lastCol = Coordinate::stringFromColumnIndex(1 + count($columns));

        $sheet->setCellValue('A1', "DHL - DOCUMENT RATE {$year}");
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        self::dhlHeader($sheet, 2, $columns, $lastCol, 'B7B7B7');
        $r = 3;
        foreach (self::lines($doc) as $line) {
            $sheet->setCellValue("A{$r}", $line['label']);
            self::priceCells($sheet, $r, 2, $columns, $doc, $line);
            $r++;
        }
        self::grid($sheet, "A3:{$lastCol}".($r - 1));
        $sheet->getStyle("A3:A".($r - 1))->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle("B3:{$lastCol}".($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        self::shadeAlternate($sheet, 3, $r - 1, $columns);
        $r = self::countryBlock($sheet, $r, $columns, 'DHL', true);
        $sheet->setCellValue('A'.($r + 1), $note);
        $sheet->getStyle('A'.($r + 1))->getFont()->setSize(8)->getColor()->setRGB('808080');
        self::widths($sheet, count($columns), 12.5);
    }

    // ---- DHL SELLING ---------------------------------------------------------------------------

    public static function dhlSelling(Worksheet $sheet, Collection $rows, array $columns, string $note): void
    {
        $sheet->setTitle('SELLING');
        $box = $rows->where('package_type', 'box');
        $lastCol = Coordinate::stringFromColumnIndex(1 + count($columns));
        self::dhlHeader($sheet, 1, $columns, $lastCol, 'CCCCCC');

        $r = 2;
        foreach (self::lines($box) as $line) {
            $sheet->setCellValue("A{$r}", $line['rate'] ? $line['label'].' KG (ต่อ KG)' : $line['label']);
            self::priceCells($sheet, $r, 2, $columns, $box, $line);
            $r++;
        }
        self::grid($sheet, "A2:{$lastCol}".($r - 1));
        $sheet->getStyle("A2:A".($r - 1))->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle("B2:{$lastCol}".($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        self::shadeAlternate($sheet, 2, $r - 1, $columns);
        $sheet->setCellValue('A'.($r + 1), '*For each box weighing  26 Kgs and up, 1,200 THB is applied for overweight charge.');
        $sheet->getStyle('A'.($r + 1))->getFont()->setBold(true);
        $sheet->setCellValue('A'.($r + 2), $note);
        $sheet->getStyle('A'.($r + 2))->getFont()->setSize(8)->getColor()->setRGB('808080');
        self::widths($sheet, count($columns), 12);
        $sheet->freezePane('B2');
    }

    private static function dhlHeader(Worksheet $sheet, int $r, array $columns, string $lastCol, string $rgb): void
    {
        $sheet->setCellValue("A{$r}", "Shipment\nWeight(Kg)");
        foreach ($columns as $i => $column) {
            // "Zone 6 US CA MX" → "Zone \n6 US CA MX", as in the rate file.
            $label = $column['extra'] ? $column['label'] : preg_replace('/^Zone\s+/', "Zone \n", $column['label']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(2 + $i).$r, $label);
        }
        self::docHeaderStyle($sheet, "A{$r}:{$lastCol}{$r}", $rgb);
    }

    // ---- shared --------------------------------------------------------------------------------

    /** SELLING (VAT incl.) of one line for every column; "ERROR" where the carrier gave no rate. */
    private static function priceCells(Worksheet $sheet, int $r, int $firstCol, array $columns, Collection $typeRows, array $line): void
    {
        $byColumn = $typeRows->filter(fn ($row) => $row->band_label.'|'.$row->weight === $line['source'])->keyBy('zone');
        foreach ($columns as $i => $column) {
            $row = $byColumn->get($column['key']);
            $cell = Coordinate::stringFromColumnIndex($firstCol + $i).$r;
            if ($row === null) {
                continue;
            }
            if ($row->error) {
                $sheet->setCellValue($cell, 'ERROR');
                $sheet->getComment($cell)->getText()->createText($row->error);

                continue;
            }
            $sheet->setCellValue($cell, round((float) $row->sell * $line['mult'], 2));
        }
    }

    /** Countries of each zone under the table, like the rate files' COUNTRY block. */
    private static function countryBlock(Worksheet $sheet, int $r, array $columns, string $carrier, bool $shade): int
    {
        $lists = [];
        foreach ($columns as $i => $column) {
            $lists[$i] = self::countryNames($carrier, $column);
        }
        $height = max(1, ...array_map('count', $lists ?: [[]]));
        $sheet->setCellValue("A{$r}", 'COUNTRY');
        $sheet->mergeCells("A{$r}:A".($r + $height - 1));
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach ($lists as $i => $names) {
            foreach ($names as $k => $name) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(2 + $i).($r + $k), $name);
            }
        }
        $range = "A{$r}:".Coordinate::stringFromColumnIndex(1 + count($columns)).($r + $height - 1);
        $sheet->getStyle($range)->getFont()->setSize(9);
        self::grid($sheet, $range);
        if ($shade) {
            self::fill($sheet, $range, self::GREY);
        }

        return $r + $height;
    }

    /** Up to 8 country names of a column (its zone, or the extra column's own country), without the Chinese suffix. */
    public static function countryNames(string $carrier, array $column): array
    {
        $query = DB::table('countries')->where('status', true);
        $column['extra']
            ? $query->where('iso2', $column['address']['iso2'] ?? '')
            : $query->where(strtolower($carrier).'_zone', $column['zone']);

        return $query->orderBy('name')->limit(8)->pluck('name')
            ->map(fn ($n) => trim(preg_replace('/\s+\S*[\x{3400}-\x{9FFF}]\S*$/u', '', $n)))->all();
    }

    private static function docHeaderStyle(Worksheet $sheet, string $range, string $rgb): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        self::fill($sheet, $range, $rgb);
        self::grid($sheet, $range);
        $sheet->getRowDimension((int) preg_replace('/\D/', '', explode(':', $range)[0]))->setRowHeight(32);
    }

    /** Grey on the extra (non-zone) columns, as on SAVE's AU / USA PR. */
    private static function shadeExtras(Worksheet $sheet, int $from, int $to, int $firstCol, array $columns): void
    {
        foreach ($columns as $i => $column) {
            if ($column['extra']) {
                $col = Coordinate::stringFromColumnIndex($firstCol + $i);
                self::fill($sheet, "{$col}{$from}:{$col}{$to}", self::GREY);
            }
        }
    }

    /** Every other column grey, as on DHL's SELLING / DOC. */
    private static function shadeAlternate(Worksheet $sheet, int $from, int $to, array $columns): void
    {
        foreach ($columns as $i => $column) {
            if ($i % 2 === 0 || $column['extra']) {
                $col = Coordinate::stringFromColumnIndex(2 + $i);
                self::fill($sheet, "{$col}{$from}:{$col}{$to}", self::GREY);
            }
        }
    }

    private static function grid(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private static function fill(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    private static function widths(Worksheet $sheet, int $count, float $width): void
    {
        $sheet->getColumnDimension('A')->setWidth(13);
        for ($c = 2; $c <= 1 + $count; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($width);
        }
    }

    /** "20.01-44.00 kg" → [20.01, 44.0]; "299.01+ kg" → [299.01, null]. */
    public static function bandRange(string $label): array
    {
        preg_match('/^([\d.,]+)(?:-([\d.,]+))?/', $label, $m);

        return [(float) str_replace(',', '', $m[1] ?? '0'), isset($m[2]) ? (float) str_replace(',', '', $m[2]) : null];
    }
}
