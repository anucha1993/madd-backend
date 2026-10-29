<?php

namespace App\Services;

use App\Models\HsCode;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Loads HS / HTS codes from an Excel file into hs_codes (the Commercial Invoice HS Code lookup on
 * /shipment/create). Columns are found by their header text, so the CPSC eFiling list
 * (database/data/CPSC_HTS_List_eFiling_Jan2026.xlsx) and a plain "HS Code | Description" sheet
 * both work. Rows are matched on code + description: re-importing updates, never duplicates.
 */
class HsCodeImporter
{
    private const HEADERS = [
        'code' => ['hts code (10-digit)', 'hts code', 'hs code', 'code'],
        'formatted' => ['hts code (formatted)'],
        'description' => ['product category', 'description'],
        'chapter' => ['hts chapter(s)', 'chapter'],
    ];

    /** @return array{created: int, updated: int, skipped: int} */
    public function import(string $path, ?string $source = null): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('HTS List') ?? $book->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);
        // PhpSpreadsheet's cells reference each other — free them now, not at process end.
        $book->disconnectWorksheets();
        unset($book, $sheet);

        [$headerIndex, $columns] = $this->findHeader($rows);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        if ($headerIndex === null) {
            return $counts;
        }

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $code = self::normalizeCode((string) ($row[$columns['code']] ?? ''));
            $description = trim((string) ($row[$columns['description']] ?? ''));
            if (strlen($code) < 6 || $description === '') {
                $counts['skipped']++;

                continue;
            }

            $record = HsCode::firstOrNew(['code' => $code, 'description' => $description]);
            $counts[$record->exists ? 'updated' : 'created']++;
            $record->fill([
                'code_formatted' => trim((string) ($row[$columns['formatted'] ?? -1] ?? '')) ?: self::format($code),
                'chapter' => isset($columns['chapter']) ? (trim((string) ($row[$columns['chapter']] ?? '')) ?: null) : null,
                'source' => $source ?? $record->source,
                'status' => $record->exists ? $record->status : true,
            ])->save();
        }

        return $counts;
    }

    /** Digits only — "8703.21.0110" → "8703210110". */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D/', '', $code);
    }

    /** "8703210110" → "8703.21.0110" (4.2.rest), the way HTS codes are usually written. */
    public static function format(string $code): string
    {
        return strlen($code) <= 4 ? $code : implode('.', array_filter([substr($code, 0, 4), substr($code, 4, 2), substr($code, 6)], fn ($part) => $part !== ''));
    }

    /** @return array{0: ?int, 1: array<string, int>} */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 15, true) as $index => $row) {
            $columns = [];
            foreach ($row as $col => $cell) {
                $text = strtolower(trim((string) $cell));
                foreach (self::HEADERS as $key => $aliases) {
                    if (! isset($columns[$key]) && in_array($text, $aliases, true)) {
                        $columns[$key] = $col;
                    }
                }
            }
            if (isset($columns['code'], $columns['description'])) {
                return [$index, $columns];
            }
        }

        return [null, []];
    }
}
