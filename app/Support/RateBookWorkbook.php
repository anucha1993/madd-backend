<?php

namespace App\Support;

use App\Models\RateBookRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One carrier's Rate Book as an Excel workbook in the business's own rate-file template
 * (madd/SAMPLE): same sheets, headings, column letters and section order — only the numbers are
 * the system's (carrier API cost + the account's markup):
 *
 *  UPS: "SAVE" + "DOC" rate cards (RateBookRateCard) · one calc sheet per column "1", "2", "JP" …
 *       (FULL DISC NET FUEL VAT SIGN INS EXTRA ADD FREE SURGE SELLING) · "ZONE"
 *  DHL: "SELLING" + "DOC" rate cards · one calc sheet per column (Zone N VAT FUEL GG MARK REMOTE
 *       PEAK SELLING COST) · "ZONE"
 *
 * SIGN / INS stay empty: the system's sell price doesn't include them. Per-kg bands are written
 * kg by kg (rate × kg) up to EXPAND_PER_KG_UP_TO, as in the samples.
 */
class RateBookWorkbook
{
    public const EXPAND_PER_KG_UP_TO = 70;

    private const UPS_YELLOW = 'FDB913';

    private const UPS_STRIPE = 'FFF6DA';

    private const DHL_YELLOW = 'FFCC00';

    /**
     * UPS zone-sheet columns D..P: [letter, header (row 1 / D = "Zone N" on the section header),
     * value(row, multiplier)]. DISC is a percentage and is never multiplied.
     */
    private static function upsColumns(): array
    {
        $m = fn (string $field) => fn ($row, float $k) => $row->{$field} === null ? null : round($row->{$field} * $k, 2);

        return [
            ['D', '', $m('full')],
            ['E', 'DISC', fn ($row) => $row->full ? round((1 - $row->freight / $row->full) * 100, 1) : null],
            ['F', 'NET', $m('freight')],
            ['G', 'FUEL', $m('fuel')],
            ['H', 'VAT', fn () => null],
            ['I', 'SIGN', fn () => null],
            ['J', 'INS', fn () => null],
            ['K', '', fn () => null],
            ['L', 'EXTRA', $m('other')],
            ['M', 'ADD', $m('markup')],
            ['N', 'FREE', $m('rounding')],
            ['O', 'SURGE', $m('surge')],
            ['P', 'SELLING', $m('sell')],
        ];
    }

    /** DHL zone-sheet columns C..M (MARK carries the whole-baht round-up too; K = other charges, unlabelled as in the sample). */
    private static function dhlColumns(): array
    {
        $m = fn (string $field) => fn ($row, float $k) => $row->{$field} === null ? null : round($row->{$field} * $k, 2);

        return [
            ['C', null, $m('freight')],
            ['D', 'VAT', fn () => null],
            ['E', 'FUEL', $m('fuel')],
            ['F', 'GG', $m('gogreen')],
            ['G', '', fn () => null],
            ['H', 'MARK', fn ($row, float $k) => round(((float) $row->markup + (float) $row->rounding) * $k, 2)],
            ['I', 'REMOTE', $m('remote')],
            ['J', 'PEAK', $m('peak')],
            ['K', '', $m('other')],
            ['L', 'SELLING', $m('sell')],
            ['M', 'COST', $m('cost')],
        ];
    }

    public static function build(RateBookRun $run, string $carrier): Spreadsheet
    {
        $rows = $run->rows()->where('carrier', $carrier)->orderBy('id')->get();
        // Only columns this run actually priced (older runs predate the extra columns).
        $columns = array_values(array_filter(
            RateBookSettings::columns($run->settings ?? [], $carrier),
            fn ($c) => $rows->contains('zone', $c['key']),
        ));
        $when = ($run->finished_at ?? $run->started_at ?? $run->created_at)->timezone(config('app.timezone'));
        $asOf = $when->format('d/m/Y H:i');
        $note = "Rate Book ข้อมูล ณ {$asOf} · SELLING = ราคาขายเดียวกับหน้า Create Shipment (API จริง + Fixed Charges + Mark-up) · THB";

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        // Rate cards first (what gets sent out), then one calc sheet per column, as in the rate files.
        if ($carrier === 'UPS') {
            RateBookRateCard::upsSave($spreadsheet->createSheet(), $rows, $columns, $note);
            RateBookRateCard::upsDoc($spreadsheet->createSheet(), $rows, $columns, $note, $when->format('Y'));
            foreach ($columns as $column) {
                self::upsZone($spreadsheet->createSheet(), $column, $rows->where('zone', $column['key']), $note);
            }
        } else {
            RateBookRateCard::dhlSelling($spreadsheet->createSheet(), $rows, $columns, $note);
            RateBookRateCard::dhlDoc($spreadsheet->createSheet(), $rows, $columns, $note, $when->format('Y'));
            foreach ($columns as $column) {
                self::dhlZone($spreadsheet->createSheet(), $column, $rows->where('zone', $column['key']), $note);
            }
        }
        self::zoneListSheet($spreadsheet->createSheet(), $carrier, $run);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    // ---- UPS ---------------------------------------------------------------------------------


    /** Like the sample's zone sheets "1".."9". */
    private static function upsZone(Worksheet $sheet, array $column, Collection $rows, string $note): void
    {
        $sheet->setTitle(mb_substr($column['key'], 0, 31));
        $zone = $column['extra'] ? $column['label'] : $column['key'];
        $columns = self::upsColumns();
        foreach ($columns as [$letter, $header]) {
            if ($header !== '') {
                $sheet->setCellValue("{$letter}1", $header);
            }
        }
        $sheet->getStyle('A1:P1')->getFont()->setBold(true);
        $sheet->setCellValue('Q1', $note);
        $sheet->getStyle('Q1')->getFont()->setSize(8)->getColor()->setRGB('808080');

        $r = 2;
        foreach (['document', 'box'] as $type) {
            $typeRows = $rows->where('package_type', $type);
            if ($typeRows->isEmpty()) {
                continue;
            }
            $sheet->setCellValue("A{$r}", $type === 'document' ? 'UPS Express® Envelope and Documents' : 'Non-Documents');
            $sheet->getStyle("A{$r}")->getFont()->setBold($type === 'box');
            $r += 2;
            $sheet->setCellValue("A{$r}", 'Shipment Weight (kg)');
            $sheet->setCellValue("D{$r}", $column['extra'] ? $zone : "Zone {$zone}");
            self::fill($sheet, "A{$r}:D{$r}", self::UPS_YELLOW);
            $r++;
            if ($type === 'document') {
                $first = $typeRows->sortBy('weight')->first();
                $sheet->setCellValue("A{$r}", 'UPS Express Envelope');
                $sheet->setCellValue("D{$r}", $first->full);
                $r++;
            }
            $r = self::detailRows($sheet, $r, 'B', $columns, 'P', $typeRows);
            $r++;
        }
        self::moneyFormat($sheet, "D2:P{$r}");
        $sheet->getStyle("E2:E{$r}")->getNumberFormat()->setFormatCode('0.0');
        $sheet->getColumnDimension('A')->setWidth(22);
        foreach (['B' => 6, 'C' => 3, 'K' => 3] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'L', 'M', 'N', 'O', 'P'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }
        $sheet->getStyle("P2:P{$r}")->getFont()->setBold(true);
    }

    // ---- DHL ---------------------------------------------------------------------------------


    /** Like the sample's zone sheets "1".."9". */
    private static function dhlZone(Worksheet $sheet, array $column, Collection $rows, string $note): void
    {
        $sheet->setTitle(mb_substr($column['key'], 0, 31));
        $zone = $column['label'];
        $columns = self::dhlColumns();
        $r = self::dhlTitles($sheet, 'M', $note);

        foreach (['document', 'box'] as $type) {
            $typeRows = $rows->where('package_type', $type);
            if ($typeRows->isEmpty()) {
                continue;
            }
            $r = self::dhlSectionHeading($sheet, $r, $type, $rows, 'M');
            $sheet->setCellValue("A{$r}", 'KG');
            foreach ($columns as [$letter, $header]) {
                $sheet->setCellValue("{$letter}{$r}", $header ?? $zone);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setSize(13);
            $r++;
            $r = self::detailRows($sheet, $r, 'A', $columns, 'L', $typeRows);
            $r++;
        }
        self::moneyFormat($sheet, "C5:M{$r}");
        $sheet->getColumnDimension('A')->setWidth(12);
        foreach (['B' => 3, 'G' => 3, 'K' => 8] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        foreach (['C', 'D', 'E', 'F', 'H', 'I', 'J', 'L', 'M'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(11);
        }
        $sheet->getStyle("L5:L{$r}")->getFont()->setBold(true);
    }

    private static function dhlTitles(Worksheet $sheet, string $lastCol, string $note): int
    {
        $sheet->setCellValue('A1', 'TIME DEFINITE');
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setSize(28);
        self::fill($sheet, 'A1', self::DHL_YELLOW);
        $sheet->setCellValue('A2', 'DHL Express Thailand');
        $sheet->getStyle('A2')->getFont()->setSize(22);
        $sheet->setCellValue('A3', 'DHL EXPRESS WORLDWIDE EXPORT');
        $sheet->getStyle('A3')->getFont()->setSize(19);
        $sheet->setCellValue('A4', $note);
        $sheet->getStyle('A4')->getFont()->setSize(8)->getColor()->setRGB('808080');

        return 5;
    }

    private static function dhlSectionHeading(Worksheet $sheet, int $r, string $type, Collection $rows, string $lastCol): int
    {
        // Documents heavier than the document band are priced as non-documents (sample wording).
        $docMax = (float) ($rows->where('package_type', 'document')->max('weight') ?? 2);
        $boxMin = (float) ($rows->where('package_type', 'box')->min('weight') ?? 0.5);
        $sheet->setCellValue("A{$r}", $type === 'document'
            ? 'Documents up to '.number_format($docMax, 1).' KG'
            : 'Non-documents from '.number_format($boxMin, 1).' KG & Documents from '.number_format($docMax + 0.5, 1).' KG');
        $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
        $sheet->getStyle("A{$r}")->getFont()->setSize(16);

        return $r + 1;
    }

    // ---- shared ------------------------------------------------------------------------------


    /** One zone's weight rows with every column; per-kg bands expand kg by kg (× kg) like the samples. */
    private static function detailRows(Worksheet $sheet, int $r, string $kgCol, array $columns, string $sellingCol, Collection $typeRows): int
    {
        $write = function (int $r, $row, float $k) use ($sheet, $columns, $sellingCol) {
            if ($row->error) {
                $sheet->setCellValue("{$sellingCol}{$r}", 'ERROR');
                $sheet->getComment("{$sellingCol}{$r}")->getText()->createText($row->error);

                return;
            }
            foreach ($columns as [$letter, , $value]) {
                $sheet->setCellValue("{$letter}{$r}", $value($row, $k));
            }
        };

        foreach ($typeRows as $row) {
            if (! $row->is_per_kg) {
                $sheet->setCellValue("A{$r}", $row->weight);
                $sheet->getStyle("A{$r}")->getNumberFormat()->setFormatCode('0.0');
                $write($r++, $row, 1);

                continue;
            }
            [$from, $to] = self::bandRange($row->band_label);
            $last = min($to ?? self::EXPAND_PER_KG_UP_TO, self::EXPAND_PER_KG_UP_TO);
            if ((int) floor($from) + 1 > $last) {
                // Beyond the expansion range: one per-kg row, named like the samples' rate rows.
                $sheet->setCellValue("A{$r}", self::bandName($from, $to).' (ต่อ kg)');
                $write($r++, $row, 1);

                continue;
            }
            for ($kg = (int) floor($from) + 1; $kg <= $last; $kg++) {
                $sheet->setCellValue("{$kgCol}{$r}", $kg);
                $write($r++, $row, $kg);
            }
        }

        return $r;
    }


    private static function zoneListSheet(Worksheet $sheet, string $carrier, RateBookRun $run): void
    {
        $sheet->setTitle('ZONE');
        $column = strtolower($carrier).'_zone';
        $reps = collect($run->settings['carriers'][$carrier]['zone_countries'] ?? [])->map(fn ($a) => $a['iso2'] ?? null);
        $sheet->fromArray(['Country', 'ISO2', "{$carrier} Zone", 'ใช้เป็นตัวแทน Zone'], null, 'A1');
        self::fill($sheet, 'A1:D1', $carrier === 'UPS' ? self::UPS_YELLOW : self::DHL_YELLOW);
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $data = DB::table('countries')->whereNotNull($column)->where($column, '!=', '')->orderBy('name')->get(['name', 'iso2', $column])
            ->map(fn ($c) => [$c->name, $c->iso2, $c->{$column}, ($reps[(string) $c->{$column}] ?? null) === $c->iso2 ? '✓' : ''])
            ->all();
        $sheet->fromArray($data, null, 'A2', true);
        foreach (['A' => 36, 'B' => 8, 'C' => 10, 'D' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->setAutoFilter('A1:D1');
    }

    private static function fill(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    private static function moneyFormat(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    /** "20.01-44.00 kg" → [20.01, 44.0]; "299.01+ kg" → [299.01, null]. */
    private static function bandRange(string $label): array
    {
        preg_match('/^([\d.,]+)(?:-([\d.,]+))?/', $label, $m);

        return [(float) str_replace(',', '', $m[1] ?? '0'), isset($m[2]) ? (float) str_replace(',', '', $m[2]) : null];
    }

    /** Same naming as the samples: 21-44, 45-70 … "300 and above". */
    private static function bandName(float $from, ?float $to): string
    {
        $start = (int) floor($from) + 1;

        return $to === null ? "{$start} and above" : $start.'-'.(int) $to;
    }
}
