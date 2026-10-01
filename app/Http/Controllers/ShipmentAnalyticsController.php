<?php

namespace App\Http\Controllers;

use App\Services\ShipmentAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Reports › Shipment Analytics (`report.summary`) — see ShipmentAnalyticsService. */
class ShipmentAnalyticsController extends Controller
{
    public function __construct(private ShipmentAnalyticsService $analytics) {}

    public function index(Request $request)
    {
        return response()->json($this->run($request));
    }

    public function export(Request $request)
    {
        $data = $this->run($request);
        $book = new Spreadsheet();
        $money = $data['show_revenue'];

        $sheet = $book->getActiveSheet()->setTitle('Summary');
        $labels = ['shipments' => 'Shipments', 'pieces' => 'Pieces', 'weight' => 'Weight (kg)', 'revenue' => 'Revenue (THB)', 'avg_revenue' => 'Avg revenue / shipment', 'delivered_rate' => 'Delivered %', 'void_rate' => 'Void %', 'voided' => 'Voided', 'avg_transit_days' => 'Avg transit (days)'];
        $sheet->fromArray([["Shipment Analytics {$data['from']} – {$data['to']} (previous {$data['previous']['from']} – {$data['previous']['to']})"], [], ['Metric', 'This period', 'Previous period']], null, 'A1');
        $r = 4;
        foreach ($labels as $key => $label) {
            if (array_key_exists($key, $data['kpis'])) {
                $sheet->fromArray([[$label, $data['kpis'][$key], $data['previous_kpis'][$key] ?? null]], null, "A{$r}", true);
                $r++;
            }
        }

        $tables = [
            'Trend' => [['Period', 'DHL', 'UPS', 'Voided', ...($money ? ['Revenue'] : [])], array_map(fn ($t) => [$t['period'], $t['DHL'], $t['UPS'], $t['voided'], ...($money ? [$t['revenue']] : [])], $data['trend'])],
            'Carriers' => [['Carrier', 'Service', 'Shipments', 'Weight (kg)', ...($money ? ['Revenue'] : [])], array_map(fn ($c) => [$c['carrier'], $c['service'], $c['shipments'], $c['weight'], ...($money ? [$c['revenue']] : [])], $data['carriers']->all())],
            'Destinations' => [['Country', 'Shipments', 'Weight (kg)', ...($money ? ['Revenue'] : [])], array_map(fn ($c) => [$c['key'], $c['shipments'], $c['weight'], ...($money ? [$c['revenue']] : [])], $data['destinations']->all())],
            'Branches' => [['Branch', 'Shipments', 'Voided', 'Weight (kg)', ...($money ? ['Revenue'] : [])], array_map(fn ($c) => [$c['name'], $c['shipments'], $c['voided'], $c['weight'], ...($money ? [$c['revenue']] : [])], $data['branches']->all())],
            'Staff' => [['Staff', 'Shipments', 'Voided', 'Weight (kg)', ...($money ? ['Revenue'] : [])], array_map(fn ($c) => [$c['name'], $c['shipments'], $c['voided'], $c['weight'], ...($money ? [$c['revenue']] : [])], $data['staff']->all())],
        ];
        foreach ($tables as $title => [$head, $rows]) {
            $ws = $book->createSheet()->setTitle($title);
            $ws->fromArray([$head], null, 'A1');
            $ws->getStyle('A1:'.$ws->getHighestColumn().'1')->getFont()->setBold(true);
            if ($rows) {
                $ws->fromArray($rows, null, 'A2', true);
            }
            foreach (range('A', $ws->getHighestColumn()) as $col) {
                $ws->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), "shipment-analytics-{$data['from']}-{$data['to']}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function run(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'group' => ['nullable', 'in:day,week,month'],
            'branch_id' => ['nullable', 'integer'],
            'carrier' => ['nullable', 'in:UPS,DHL'],
            'created_by' => ['nullable', 'integer'],
        ]);
        $tz = 'Asia/Bangkok';
        $to = Carbon::parse($data['date_to'] ?? now($tz)->toDateString(), $tz)->endOfDay();
        $from = Carbon::parse($data['date_from'] ?? $to->copy()->subDays(29)->toDateString(), $tz)->startOfDay();
        $days = $from->diffInDays($to) + 1;
        abort_if($days > 400, 422, 'ช่วงวันที่ต้องไม่เกิน 400 วัน');
        $group = $data['group'] ?? ($days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month'));

        return $this->analytics->build($request->user(), $from, $to, $group, array_intersect_key($data, array_flip(['branch_id', 'carrier', 'created_by'])));
    }
}
