<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One-sheet Excel template/data download + upload parsing for config screens (country zones,
 * zone prices): row 1 = machine header, rows after it = data; a second "วิธีใช้" sheet explains
 * the columns. Upload accepts .xlsx/.xls/.csv.
 */
class SimpleSheet
{
    /** @param  array<int, string>  $header  @param  array<int, array<int, mixed>>  $rows  @param  array<int, string>  $help */
    public static function download(string $filename, string $title, array $header, array $rows, array $help = []): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle($title);
        $sheet->fromArray([$header], null, 'A1');
        $lastColumn = chr(ord('A') + count($header) - 1);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        if ($rows) {
            // Every cell as text — keeps zones like "01" and codes like "0375" exactly as typed.
            foreach ($rows as $i => $row) {
                foreach (array_values($row) as $j => $value) {
                    $cell = chr(ord('A') + $j).($i + 2);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    is_float($value) || is_int($value)
                        ? $sheet->setCellValue($cell, $value)
                        : $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        if ($help) {
            $helpSheet = $spreadsheet->createSheet()->setTitle('วิธีใช้');
            $helpSheet->fromArray(array_map(fn ($line) => [$line], $help), null, 'A1');
            $helpSheet->getStyle('A1')->getFont()->setBold(true);
            $helpSheet->getColumnDimension('A')->setWidth(110);
        }
        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($spreadsheet))->save('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * First sheet only. Header names are lower-cased/trimmed; empty rows are skipped.
     *
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    public static function read(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $matrix = $sheet->toArray(null, true, false, false);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($matrix) ?? []);

        $rows = [];
        foreach ($matrix as $line) {
            if (! array_filter($line, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $row[$name] = $line[$i] ?? null;
                }
            }
            $rows[] = $row;
        }

        return [$header, $rows];
    }
}
