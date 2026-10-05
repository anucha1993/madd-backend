<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Services\KeyBillingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class KeyBillingReportController extends Controller
{
    public function __construct(private KeyBillingReportService $keyBillingReportService)
    {
    }

    /**
     * Resolves the "Daily/Weekly/Monthly/Yearly/Custom" quick-range presets (same convention as
     * ManifestReportController::resolveDateRange) into a concrete [from, to] date pair.
     *
     * @param  array{range?: string, date_from?: string, date_to?: string}  $filters
     */
    private function resolveDateRange(array $filters): array
    {
        $preset = $filters['range'] ?? 'monthly';
        $today = now()->startOfDay();

        return match ($preset) {
            'daily' => [$today, now()->endOfDay()],
            'weekly' => [$today->copy()->startOfWeek(), now()->endOfDay()],
            'yearly' => [$today->copy()->startOfYear(), now()->endOfDay()],
            'custom' => [
                ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : $today,
                ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : now()->endOfDay(),
            ],
            default => [$today->copy()->startOfMonth(), now()->endOfDay()],
        };
    }

    /**
     * @param  array{range?: string, date_from?: string, date_to?: string, branch_id?: int|string, carrier?: string}  $filters
     */
    private function queryShipments(Request $request, array $filters, array $with)
    {
        [$from, $to] = $this->resolveDateRange($filters);

        $query = Shipment::visibleTo($request->user())
            ->with($with)
            ->where('status', 'booked')
            ->whereBetween('created_at', [$from, $to]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['carrier'])) {
            $query->where('carrier', $filters['carrier']);
        }

        return $query->orderBy('created_at')->get();
    }

    public function index(Request $request)
    {
        $filters = $request->query();
        $shipments = $this->queryShipments($request, $filters, [
            'branch:id,name', 'agentAccount:id,username_acc', 'carrierInvoiceLines.invoice:id,invoice_no',
        ]);

        return response()->json([
            'rows' => $this->keyBillingReportService->buildRows($shipments),
            'total_shipments' => $shipments->count(),
        ]);
    }

    /** Column letter => [data key, header label] for the SHIPMENTS identity group (A:S). */
    private const IDENTITY_COLUMNS = [
        'B' => ['date', 'DATE'], 'C' => ['account', 'ACCOUNT'], 'D' => ['tracking', 'TRACKING'],
        'E' => ['ref', 'REF.'], 'F' => ['zone', 'Zone'], 'G' => ['dest', 'DEST'],
        'H' => ['shipper', 'Shipper'], 'I' => ['shipper_tax_id', "Shipper\nTax ID"], 'J' => ['shipper_address', "Shipper \nAddress"],
        'K' => ['consignee', 'Consignee'], 'L' => ['consignee_tax_id', "Consignee\nTax ID"], 'M' => ['consignee_address', "Consignee \nAddress"],
        'N' => ['customer_type', "Customer \nType"], 'O' => ['product', 'PRODUCT'], 'P' => ['service_type', 'SERVICE TYPE'],
        'Q' => ['pkgs', "จำนวน \nPkgs"], 'R' => ['act_kg', "Act\nKgs."], 'S' => ['dim_kg', "Dim\nKgs."],
    ];

    /** Column letter => [data key, header label] for the COST (INVOICE FROM carrier) group (U:AB). */
    private const COST_COLUMNS = [
        'U' => ['cost_freight', 'Freight'], 'V' => ['cost_fuel', "Fuel \nSurcharge"], 'W' => ['cost_form', 'FORM'],
        'X' => ['cost_ot', 'OT'], 'Y' => ['cost_adult_sig', "Adult Signature \nRequired"], 'Z' => ['cost_surge_com', 'Surge Fee - Com'],
        'AA' => ['cost_large_pkg', "Large Package \nSurcharge"], 'AB' => ['cost_total', 'Total'],
    ];

    /** Column letter => [data key, header label] for the SALE FROM MANIFEST group (AD:AT) — AE/AF (INS.), AK:AL and AN:AO are 2-col headers handled separately in writeHeader(). */
    private const SALE_COLUMNS = [
        'AD' => ['sell_freight', "SELLING \nFREIGHT"], 'AE' => ['ins_silver', 'SILVER'], 'AF' => ['ins_non_silver', "NON \nSILVER"],
        'AG' => ['sell_form', 'FORM'], 'AH' => ['sell_ot', 'OT'], 'AI' => ['sell_residential', "Residential \nSurcharge"],
        'AJ' => ['sell_add_handling', "Additional \nHandling"], 'AK' => ['sell_adult_sig', "Adult Signature \nRequired"],
        'AM' => ['sell_surge_com', 'Surge Fee - Com'], 'AN' => ['sell_surcharge', ' Surcharge '],
        'AP' => ['sell_intl_processing', "International \nProcessing \nFee"], 'AQ' => ['sell_large_pkg', "Large \nPackage \nSurcharge"],
        'AR' => ['sell_declared_value', "Declared \nValue"], 'AS' => ['sell_other', 'OTHER'], 'AT' => ['sell_total', 'TOTAL'],
    ];

    /** Column letter => [data key, header label] for OTHER CHARGE (AV:AW), SELLING GRAND TOTAL (AX), Payment Option (AY:BI), INV. VALUE (BJ:BK). */
    private const TAIL_COLUMNS = [
        'AV' => ['metal_box', "METAL \nBOX"], 'AW' => ['other_charge', 'OTHER'],
        'AX' => ['selling_grand_total', null],
        'AY' => ['pay_daily', 'Daily'], 'AZ' => ['pay_cr', 'CR'], 'BA' => ['pay_qr', 'QR'], 'BB' => ['pay_transfer', 'TRANSFER'],
        'BC' => ['pay_card', 'CARD'], 'BE' => ['pay_chq', 'CHQ'], 'BF' => ['pay_pending', 'Pending'],
        'BH' => ['pay_other', "OTHER \n"], 'BI' => ['remark', 'REMARK'],
        'BJ' => ['inv_value_silver', 'SILVER'], 'BK' => ['inv_value_non_silver', 'NON SILVER'],
    ];

    public function export(Request $request)
    {
        $filters = $request->query();
        $shipments = $this->queryShipments($request, $filters, [
            'branch:id,name', 'agentAccount:id,username_acc', 'receipts', 'carrierInvoiceLines.invoice:id,invoice_no',
        ]);
        $rows = $this->keyBillingReportService->buildTemplateRows($shipments);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Key Billing Report');
        $this->writeTemplateHeader($sheet);
        $this->writeTemplateRows($sheet, $rows);

        app(\App\Services\AuditLogger::class)->accessed('exported', 'KeyBillingReport', ['filters' => $filters], 'Key Billing Report Excel');

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'key-billing-report-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Replicates the 3-row merged header exactly as found in the reference template
     * "ข้อมูลดึงเป็นรายงาน key billing.xlsx" (read 2026-10-02) — same merge ranges, same group
     * labels, same sub-column labels. T/AC/AU are intentionally blank 1-px gap columns in the
     * original and are left untouched here too.
     */
    private function writeTemplateHeader($sheet): void
    {
        $sheet->setCellValue('A1', 'SHIPMENTS');
        $sheet->mergeCells('A1:S2');
        $sheet->setCellValue('A3', 'NO.');
        foreach (self::IDENTITY_COLUMNS as $col => [, $label]) {
            $sheet->setCellValue("{$col}3", $label);
        }

        $sheet->setCellValue('U1', 'COST   ( INVOICE FROM UPS/DHL)');
        $sheet->mergeCells('U1:AB2');
        foreach (self::COST_COLUMNS as $col => [, $label]) {
            $sheet->setCellValue("{$col}3", $label);
        }

        $sheet->setCellValue('AD1', '  SALE ( SALE FROM MANIFEST )');
        $sheet->mergeCells('AD1:AT1');
        $sheet->setCellValue('AD2', self::SALE_COLUMNS['AD'][1]);
        $sheet->mergeCells('AD2:AD3');
        $sheet->setCellValue('AE2', 'INS.');
        $sheet->mergeCells('AE2:AF2');
        $sheet->setCellValue('AE3', 'SILVER');
        $sheet->setCellValue('AF3', self::SALE_COLUMNS['AF'][1]);
        foreach (['AG', 'AH', 'AI', 'AJ'] as $col) {
            $sheet->setCellValue("{$col}2", self::SALE_COLUMNS[$col][1]);
            $sheet->mergeCells("{$col}2:{$col}3");
        }
        $sheet->setCellValue('AK2', self::SALE_COLUMNS['AK'][1]);
        $sheet->mergeCells('AK2:AL3');
        $sheet->setCellValue('AM2', self::SALE_COLUMNS['AM'][1]);
        $sheet->mergeCells('AM2:AM3');
        $sheet->setCellValue('AN2', self::SALE_COLUMNS['AN'][1]);
        $sheet->mergeCells('AN2:AO3');
        foreach (['AP', 'AQ', 'AR', 'AS', 'AT'] as $col) {
            $sheet->setCellValue("{$col}2", self::SALE_COLUMNS[$col][1]);
            $sheet->mergeCells("{$col}2:{$col}3");
        }

        $sheet->setCellValue('AV1', "OTHER CHARGE\nรายการเก็บเงินอื่นๆ ");
        $sheet->mergeCells('AV1:AW2');
        $sheet->setCellValue('AV3', self::TAIL_COLUMNS['AV'][1]);
        $sheet->setCellValue('AW3', self::TAIL_COLUMNS['AW'][1]);

        $sheet->setCellValue('AX1', 'SELLING GRAND TOTAL');
        $sheet->mergeCells('AX1:AX3');

        $sheet->setCellValue('AY1', 'Payment Option');
        $sheet->mergeCells('AY1:BI2');
        $sheet->setCellValue('AY3', self::TAIL_COLUMNS['AY'][1]);
        $sheet->setCellValue('AZ3', self::TAIL_COLUMNS['AZ'][1]);
        $sheet->setCellValue('BA3', self::TAIL_COLUMNS['BA'][1]);
        $sheet->setCellValue('BB3', self::TAIL_COLUMNS['BB'][1]);
        $sheet->setCellValue('BC3', self::TAIL_COLUMNS['BC'][1]);
        $sheet->mergeCells('BC3:BD3');
        $sheet->setCellValue('BE3', self::TAIL_COLUMNS['BE'][1]);
        $sheet->setCellValue('BF3', self::TAIL_COLUMNS['BF'][1]);
        $sheet->mergeCells('BF3:BG3');
        $sheet->setCellValue('BH3', self::TAIL_COLUMNS['BH'][1]);
        $sheet->setCellValue('BI3', self::TAIL_COLUMNS['BI'][1]);

        $sheet->setCellValue('BJ1', 'INV.  VALUE');
        $sheet->mergeCells('BJ1:BK2');
        $sheet->setCellValue('BJ3', self::TAIL_COLUMNS['BJ'][1]);
        $sheet->setCellValue('BK3', self::TAIL_COLUMNS['BK'][1]);

        $sheet->getStyle('A1:BK3')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A1:BK3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A1:BK3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getRowDimension(1)->setRowHeight(18);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(30);
        $sheet->getColumnDimension('T')->setWidth(1);
        $sheet->getColumnDimension('AC')->setWidth(1);
        $sheet->getColumnDimension('AU')->setWidth(1);
    }

    /** 2-column header pairs that stay merged on EVERY data row too (one logical value spanning 2 excel columns, matching the header's own merge). */
    private const DATA_ROW_MERGED_PAIRS = ['AK:AL', 'AN:AO', 'BC:BD', 'BF:BG'];

    private function writeTemplateRows($sheet, array $rows): void
    {
        $row = 4;
        foreach ($rows as $i => $data) {
            $sheet->setCellValue("A{$row}", $i + 1);
            foreach (self::IDENTITY_COLUMNS as $col => [$key]) {
                $sheet->setCellValue("{$col}{$row}", $data[$key] instanceof Carbon ? $data[$key]->format('d/m/Y') : $data[$key]);
            }
            foreach (self::COST_COLUMNS as $col => [$key]) {
                $sheet->setCellValue("{$col}{$row}", (float) $data[$key]);
            }
            foreach (self::SALE_COLUMNS as $col => [$key]) {
                $sheet->setCellValue("{$col}{$row}", (float) $data[$key]);
            }
            foreach (self::TAIL_COLUMNS as $col => [$key]) {
                $value = $data[$key];
                $sheet->setCellValue("{$col}{$row}", is_numeric($value) ? (float) $value : $value);
            }
            foreach (self::DATA_ROW_MERGED_PAIRS as $pair) {
                [$start, $end] = explode(':', $pair);
                $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            }
            $row++;
        }

        $lastRow = $row - 1;
        if ($lastRow >= 4) {
            $sheet->getStyle("A4:BK{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);
            $sheet->getStyle("A4:BK{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        } else {
            $sheet->setCellValue('A4', 'No shipments found for the selected filters.');
        }

        foreach (array_keys(self::IDENTITY_COLUMNS) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        foreach (['B' => 11, 'C' => 15, 'D' => 18, 'H' => 20, 'J' => 22, 'K' => 20, 'M' => 22] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }
}
