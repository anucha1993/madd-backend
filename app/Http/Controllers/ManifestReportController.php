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
        'tracking' => 'Tracking', 'ref' => 'Ref', 'zone' => 'Zone',
        'weight_act' => 'Act', 'weight_dim' => 'Dim',
        'pay' => 'Pay', 'dest' => 'Dest', 'type' => 'Type', 'pkg' => 'Pkg',
        'shipper' => 'Shipper', 'consignee' => 'Consignee',
        'freight' => 'Freight', 'sur' => 'Sur', 'accs' => 'Accs',
        'ins' => 'Ins.', 'ins_co' => 'Ins-Co', 'metal' => 'Metal', 'form' => 'Form', 'other' => 'Other',
        'total_charge' => 'Total Charge', 'remark' => 'Remark', 'inv_value' => 'Inv.Value',
    ];

    /**
     * Resolves the "Daily/Weekly/Monthly/Yearly/Custom" quick-range presets shown on /manifest
     * into a concrete [from, to] date pair — Custom just passes date_from/date_to straight
     * through (already validated as required together in that case).
     */
    private function resolveDateRange(Request $request): array
    {
        $preset = $request->query('range', 'daily');
        $today = now()->startOfDay();

        return match ($preset) {
            'weekly' => [$today->copy()->startOfWeek(), now()->endOfDay()],
            'monthly' => [$today->copy()->startOfMonth(), now()->endOfDay()],
            'yearly' => [$today->copy()->startOfYear(), now()->endOfDay()],
            'custom' => [
                $request->query('date_from') ? \Illuminate\Support\Carbon::parse($request->query('date_from'))->startOfDay() : $today,
                $request->query('date_to') ? \Illuminate\Support\Carbon::parse($request->query('date_to'))->endOfDay() : now()->endOfDay(),
            ],
            default => [$today, now()->endOfDay()],
        };
    }

    private function queryShipments(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);

        $query = Shipment::with(['agentAccount.agent', 'branch'])
            ->where('status', 'booked')
            ->whereBetween('created_at', [$from, $to]);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('carrier')) {
            $query->where('carrier', $request->query('carrier'));
        }
        if ($request->filled('agent_account_id')) {
            $query->where('agent_account_id', $request->query('agent_account_id'));
        }

        return $query->orderBy('branch_id')->orderBy('agent_account_id')->orderBy('created_at')->get();
    }

    /** JSON preview shown on-screen before exporting — same grouping/columns as the Excel file. */
    public function index(Request $request)
    {
        $shipments = $this->queryShipments($request);
        [$from, $to] = $this->resolveDateRange($request);

        return response()->json([
            'groups' => $this->manifestReportService->buildGroups($shipments, $from, $to),
            'total_shipments' => $shipments->count(),
        ]);
    }

    public function export(Request $request)
    {
        $shipments = $this->queryShipments($request);
        [$from, $to] = $this->resolveDateRange($request);
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

        $filename = 'manifest-'.now()->format('Ymd-His').'.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        // Without an explicit height, the 16pt font overflows past the default row height into
        // whichever cells below happen to be empty in that column.
        $sheet->getRowDimension($row)->setRowHeight(24);
        $row++;

        $sheet->setCellValue("A{$row}", 'Date :');
        $sheet->setCellValue("B{$row}", $header['date']);
        $sheet->setCellValue("D{$row}", 'Account Number');
        $sheet->setCellValue("F{$row}", $header['account_number']);
        $sheet->setCellValue("H{$row}", 'Branch');
        $sheet->setCellValue("J{$row}", "{$header['branch_name']} ({$header['branch_code']})");
        $sheet->setCellValue($lastCol.$row, $header['carrier']);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        $row += 2;

        $headerRow = $row;
        foreach (array_values(self::COLUMNS) as $i => $label) {
            $sheet->setCellValue($columnLetters[$i].$row, $label);
        }
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        foreach ($group['rows'] as $data) {
            foreach (array_keys(self::COLUMNS) as $i => $key) {
                $sheet->setCellValue($columnLetters[$i].$row, $data[$key]);
            }
            $row++;
        }

        $sheet->getStyle("A{$headerRow}:{$lastCol}".($row - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach ($columnLetters as $letter) {
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
    }
}
