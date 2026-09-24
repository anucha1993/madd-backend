<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Services\ManifestReportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ManifestReportController extends Controller
{
    public function __construct(private ManifestReportService $manifestReportService)
    {
    }

    private const COLUMNS = [
        'tracking' => 'Tracking', 'ref' => 'Vol./No.', 'zone' => 'Zone',
        'weight_act' => 'Act', 'weight_dim' => 'Dim',
        'pay' => 'Pay', 'dest' => 'Dest', 'type' => 'Type', 'pkg' => 'Pkg',
        'shipper' => 'Shipper', 'consignee' => 'Consignee',
        'freight' => 'Freight', 'sur' => 'Sur', 'accs' => 'Accs',
        'ins' => 'Ins.', 'ins_co' => 'Ins-Co', 'metal' => 'Metal', 'form' => 'Form', 'other' => 'Other',
        'total_charge' => 'Total Charge', 'remark' => 'Remark', 'inv_value' => 'Inv.Value',
    ];

    /** Matches the reference manifest template's manually-tuned column widths. */
    private const COLUMN_WIDTHS = [
        'tracking' => 24.14, 'ref' => 19.43, 'zone' => 6.71,
        'weight_act' => 18.71, 'weight_dim' => 15,
        'pay' => 8.71, 'dest' => 8.86, 'type' => 13, 'pkg' => 7.43,
        'shipper' => 33.14, 'consignee' => 25.43,
        'freight' => 19.14, 'sur' => 7.43, 'accs' => 8.86,
        'ins' => 13, 'ins_co' => 11.29, 'metal' => 10, 'form' => 8.86, 'other' => 26.86,
        'total_charge' => 28, 'remark' => 11.29, 'inv_value' => 15.14,
    ];

    /**
     * Resolves the "Daily/Weekly/Monthly/Yearly/Custom" quick-range presets shown on /manifest
     * into a concrete [from, to] date pair — Custom just passes date_from/date_to straight
     * through (already validated as required together in that case).
     *
     * @param  array{range?: string, date_from?: string, date_to?: string}  $filters
     */
    private function resolveDateRange(array $filters): array
    {
        $preset = $filters['range'] ?? 'daily';
        $today = now()->startOfDay();

        return match ($preset) {
            'weekly' => [$today->copy()->startOfWeek(), now()->endOfDay()],
            'monthly' => [$today->copy()->startOfMonth(), now()->endOfDay()],
            'yearly' => [$today->copy()->startOfYear(), now()->endOfDay()],
            'custom' => [
                ! empty($filters['date_from']) ? \Illuminate\Support\Carbon::parse($filters['date_from'])->startOfDay() : $today,
                ! empty($filters['date_to']) ? \Illuminate\Support\Carbon::parse($filters['date_to'])->endOfDay() : now()->endOfDay(),
            ],
            default => [$today, now()->endOfDay()],
        };
    }

    /**
     * @param  array{range?: string, date_from?: string, date_to?: string, branch_id?: int|string, carrier?: string, agent_account_id?: int|string}  $filters
     */
    private function queryShipments(array $filters)
    {
        [$from, $to] = $this->resolveDateRange($filters);

        // Only shipments that actually have an ISSUED Receipt/Tax Invoice belong on a manifest
        // (this is a billing-facing document, not a raw booking log) — VOIDED receipts don't
        // count, same as the "issued" semantics used everywhere else in the app.
        $query = Shipment::with(['agentAccount.agent', 'branch', 'receipts' => function ($q) {
            $q->where('status', 'ISSUED');
        }])
            ->where('status', 'booked')
            ->whereHas('receipts', function ($q) {
                $q->where('status', 'ISSUED');
            })
            ->whereBetween('created_at', [$from, $to]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['carrier'])) {
            $query->where('carrier', $filters['carrier']);
        }
        if (! empty($filters['agent_account_id'])) {
            $query->where('agent_account_id', $filters['agent_account_id']);
        }

        return $query->orderBy('branch_id')->orderBy('agent_account_id')->orderBy('created_at')->get();
    }

    /** JSON preview shown on-screen before exporting — same grouping/columns as the Excel file. */
    public function index(Request $request)
    {
        $filters = $request->query();
        $shipments = $this->queryShipments($filters);
        [$from, $to] = $this->resolveDateRange($filters);

        return response()->json([
            'groups' => $this->manifestReportService->buildGroups($shipments, $from, $to),
            'total_shipments' => $shipments->count(),
        ]);
    }

    public function export(Request $request)
    {
        $content = $this->buildManifestXlsx($request->query());
        $filename = 'manifest-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Builds the raw .xlsx bytes for the given filters — shared by the HTTP export() download
     * and the scheduled-report mailer (App\Console\Commands\SendScheduledReports) so both paths
     * produce byte-identical output.
     *
     * @param  array{range?: string, date_from?: string, date_to?: string, branch_id?: int|string, carrier?: string, agent_account_id?: int|string}  $filters
     */
    public function buildManifestXlsx(array $filters): string
    {
        $shipments = $this->queryShipments($filters);
        [$from, $to] = $this->resolveDateRange($filters);
        $groups = $this->manifestReportService->buildGroups($shipments, $from, $to);

        $spreadsheet = new Spreadsheet();
        $columnLetters = array_map(fn ($i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1), range(0, count(self::COLUMNS) - 1));
        $lastCol = end($columnLetters);

        if (empty($groups)) {
            $spreadsheet->getActiveSheet()->setTitle('Manifest')->setCellValue('A1', 'No shipments found for the selected filters.');
        }

        // One worksheet TAB per Account/Branch (not stacked in a single sheet) — each carrier
        // account is its own physical manifest, so each gets its own tab to open independently.
        $usedTitles = [];
        foreach ($groups as $i => $group) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueSheetTitle($group['header'], $usedTitles));
            $this->writeGroupSheet($sheet, $group, $columnLetters, $lastCol);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return ob_get_clean();
    }

    /** Excel sheet titles: max 31 chars, no \ / ? * [ ] : characters, and must be unique per workbook. */
    private function uniqueSheetTitle(array $header, array &$usedTitles): string
    {
        $raw = trim("{$header['carrier']} {$header['account_number']}");
        $base = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $raw), 0, 28) ?: 'Manifest';

        $title = $base;
        $suffix = 2;
        while (in_array($title, $usedTitles, true)) {
            $title = substr($base, 0, 28).' '.$suffix;
            $suffix++;
        }
        $usedTitles[] = $title;

        return $title;
    }

    private function writeGroupSheet($sheet, array $group, array $columnLetters, string $lastCol): void
    {
        $header = $group['header'];
        $row = 1;

        $sheet->setCellValue("A{$row}", 'MANIFEST');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(26);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(32.25);
        $row++;

        // Info row: label/value pairs merged across the same column boundaries as the data
        // table below (Date, Account Number, Branch label+value, carrier) so the grid lines up.
        $infoRow = $row;
        $sheet->setCellValue("A{$infoRow}", 'Date :');
        $sheet->setCellValue("B{$infoRow}", $header['date']);
        $sheet->mergeCells("B{$infoRow}:C{$infoRow}");
        $sheet->setCellValue("D{$infoRow}", 'Account Number');
        $sheet->setCellValue("E{$infoRow}", $header['account_number']);
        $sheet->mergeCells("E{$infoRow}:F{$infoRow}");
        $sheet->setCellValue("G{$infoRow}", 'Branch');
        $sheet->mergeCells("G{$infoRow}:I{$infoRow}");
        $sheet->setCellValue("J{$infoRow}", "{$header['branch_name']} ({$header['branch_code']})");
        $sheet->mergeCells("J{$infoRow}:U{$infoRow}");
        $sheet->setCellValue($lastCol.$infoRow, $header['carrier']);
        $sheet->getStyle("A{$infoRow}:{$lastCol}{$infoRow}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$infoRow}:{$lastCol}{$infoRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("E{$infoRow}:I{$infoRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("J{$infoRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension($infoRow)->setRowHeight(38.25);
        $row += 2;

        // Two-row column header to match the reference manifest template: "Weight" spans as a
        // merged super-header over its Act/Dim sub-columns, while every other column merges
        // vertically across both header rows so it still reads as one label.
        $headerRow = $row;
        $headerSubRow = $row + 1;
        foreach (array_keys(self::COLUMNS) as $i => $key) {
            $label = self::COLUMNS[$key];
            $col = $columnLetters[$i];
            if ($key === 'weight_act') {
                $sheet->setCellValue($col.$headerRow, 'Weight');
                $sheet->mergeCells("{$col}{$headerRow}:{$columnLetters[$i + 1]}{$headerRow}");
                $sheet->setCellValue($col.$headerSubRow, $label);
            } elseif ($key === 'weight_dim') {
                $sheet->setCellValue($col.$headerSubRow, $label);
            } else {
                $sheet->setCellValue($col.$headerRow, $label);
                $sheet->mergeCells("{$col}{$headerRow}:{$col}{$headerSubRow}");
            }
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerSubRow}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerSubRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerSubRow}")->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerSubRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(15.75);
        $sheet->getRowDimension($headerSubRow)->setRowHeight(15.75);
        $row = $headerSubRow + 1;

        foreach ($group['rows'] as $data) {
            foreach (array_keys(self::COLUMNS) as $i => $key) {
                $sheet->setCellValue($columnLetters[$i].$row, $data[$key]);
            }
            $sheet->getRowDimension($row)->setRowHeight(31.5);
            $row++;
        }

        // Data rows default to a larger-than-PhpSpreadsheet's-default 10pt (user reported the
        // exported sheet reads too small) — bump the whole body to 12pt, matching the header rows.
        $dataRange = 'A'.($headerSubRow + 1).":{$lastCol}".($row - 1);
        $sheet->getStyle($dataRange)->getFont()->setSize(12);
        $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("A1:{$lastCol}".($row - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);

        foreach ($columnLetters as $i => $letter) {
            $sheet->getColumnDimension($letter)->setWidth(self::COLUMN_WIDTHS[array_keys(self::COLUMNS)[$i]]);
        }
    }
}
