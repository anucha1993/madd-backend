<?php

namespace App\Services;

use App\Models\HsCode;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Loads HS / HTS codes from an Excel or CSV file into hs_codes (the Commercial Invoice HS Code
 * lookup on /shipment/create). Columns are found by their header text, so the CPSC eFiling list
 * (database/data/CPSC_HTS_List_eFiling_Jan2026.xlsx), the HS 2022 list from UN Comtrade
 * (database/data/HS2022_UN_Comtrade.csv) and a plain "HS Code | Description" sheet all work.
 * Rows are matched on code + description: re-importing updates, never duplicates, and keeps a
 * row's Active/Inactive status. Codes shorter than 6 digits (chapters, headings) are skipped.
 */
class HsCodeImporter
{
    private const HEADERS = [
        'code' => ['hts code (10-digit)', 'hts code', 'hs code', 'hscode', 'code'],
        'formatted' => ['hts code (formatted)'],
        'description' => ['product category', 'description'],
        'chapter' => ['hts chapter(s)', 'chapter'],
    ];

    /**
     * @param  ?string  $extension  the file's real extension when $path has none (an upload's temp file)
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(string $path, ?string $source = null, ?string $extension = null): array
    {
        $rows = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION)) === 'csv' ? $this->csvRows($path) : $this->spreadsheetRows($path);

        [$headerIndex, $columns] = $this->findHeader($rows);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        if ($headerIndex === null) {
            return $counts;
        }

        $records = [];
        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $code = self::normalizeCode((string) ($row[$columns['code']] ?? ''));
            $description = mb_substr(trim((string) ($row[$columns['description']] ?? '')), 0, 255);
            if (strlen($code) < 6 || $description === '') {
                $counts['skipped']++;

                continue;
            }
            $chapter = isset($columns['chapter']) ? trim((string) ($row[$columns['chapter']] ?? '')) : '';
            $records[$code."\0".$description] = [
                'code' => $code,
                'description' => $description,
                'code_formatted' => trim((string) ($row[$columns['formatted'] ?? -1] ?? '')) ?: self::format($code),
                'chapter' => $chapter !== '' ? $chapter : substr($code, 0, 2),
            ];
        }

        // One query to know what's there already, then batched upserts — thousands of rows go
        // to a remote database, one round trip per row would take minutes.
        $existing = [];
        unset($rows);
        foreach (HsCode::query()->toBase()->get(['code', 'description', 'source']) as $hs) {
            $existing[$hs->code."\0".$hs->description] = $hs->source;
        }
        $now = now();
        $batch = [];
        foreach ($records as $key => $record) {
            $isExisting = array_key_exists($key, $existing);
            $counts[$isExisting ? 'updated' : 'created']++;
            $batch[] = $record + [
                'source' => $source ?? ($isExisting ? $existing[$key] : null),
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($batch, 500) as $chunk) {
            // status / created_at are left out of the update list: an existing row keeps them.
            HsCode::upsert($chunk, ['code', 'description'], ['code_formatted', 'chapter', 'source', 'updated_at']);
        }

        return $counts;
    }

    /** A CSV read directly — PhpSpreadsheet holds every cell as an object, far too heavy for thousands of rows. */
    private function csvRows(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', $rows[0][0]); // UTF-8 BOM
        }

        return $rows;
    }

    private function spreadsheetRows(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('HTS List') ?? $book->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);
        // PhpSpreadsheet's cells reference each other — free them now, not at process end.
        $book->disconnectWorksheets();

        return $rows;
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
