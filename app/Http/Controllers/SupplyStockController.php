<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Supply;
use App\Models\SupplyStock;
use App\Models\SupplyStockMovement;
use App\Services\AccessService;
use App\Services\SupplyStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Packing Supplies stock per branch (Config › Packing Supplies › Stock). The `supply_stock`
 * data scope decides which branches a user works with: 'all' (or can_access_all_branches) →
 * every branch, otherwise only the user's own branches (stock has no per-user owner, so 'own'
 * behaves like 'branch').
 */
class SupplyStockController extends Controller
{
    public function __construct(private AccessService $access, private SupplyStockService $stock) {}

    /** Balance matrix: every active supply × every branch the user can see (missing rows = 0). */
    public function index(Request $request)
    {
        $branches = $this->branches($request);
        $stocks = SupplyStock::whereIn('branch_id', $branches->pluck('id'))->get()->groupBy('supply_id');

        $supplies = Supply::orderBy('name')->get(['id', 'name', 'type', 'status', 'cost_price', 'sale_price'])
            ->map(fn (Supply $supply) => $supply->toArray() + [
                'stocks' => $branches->map(function (Branch $branch) use ($stocks, $supply) {
                    $row = $stocks->get($supply->id)?->firstWhere('branch_id', $branch->id)
                        ?? new SupplyStock(['supply_id' => $supply->id, 'branch_id' => $branch->id, 'quantity' => 0]);

                    return $row->only(['branch_id', 'quantity', 'min_qty', 'max_qty', 'level']);
                })->values(),
            ]);

        return response()->json(['branches' => $branches->values(), 'supplies' => $supplies]);
    }

    public function movements(Request $request)
    {
        $data = $request->validate([
            'supply_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(SupplyStockMovement::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = SupplyStockMovement::with('supply:id,name', 'branch:id,name,code', 'user:id,name', 'shipment:id,tracking_number')
            ->whereIn('branch_id', $this->branchIds($request, $data['branch_id'] ?? null))
            ->latest('created_at')->latest('id');
        foreach (['supply_id', 'type'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        return response()->json($query->paginate(30));
    }

    /** Receive stock (รับเข้า) — positive quantity. */
    public function receive(Request $request)
    {
        $data = $request->validate($this->lineRules() + ['quantity' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $this->assertBranch($request, $data['branch_id']);

        $movement = $this->stock->move($data['supply_id'], $data['branch_id'], $data['quantity'], 'receive', $this->attrs($request, $data));

        return response()->json($movement->load('supply:id,name', 'branch:id,name,code'), 201);
    }

    /** Stock count (ปรับยอด) — the counted balance; records the difference. */
    public function adjust(Request $request)
    {
        $data = $request->validate($this->lineRules() + [
            'counted' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['required', 'string', 'max:500'],
        ]);
        $this->assertBranch($request, $data['branch_id']);

        $movement = $this->stock->setCounted($data['supply_id'], $data['branch_id'], $data['counted'], $this->attrs($request, $data));

        return response()->json(['movement' => $movement?->load('supply:id,name', 'branch:id,name,code')]);
    }

    public function limits(Request $request)
    {
        $data = $request->validate([
            'supply_id' => ['required', 'integer', 'exists:supplies,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'min_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_qty' => ['nullable', 'integer', 'min:0', 'max:1000000', 'gte:min_qty'],
        ]);
        $this->assertBranch($request, $data['branch_id']);

        $stock = $this->stock->setLimits($data['supply_id'], $data['branch_id'], $data['min_qty'] ?? null, $data['max_qty'] ?? null);

        return response()->json($stock->only(['supply_id', 'branch_id', 'quantity', 'min_qty', 'max_qty', 'level']));
    }

    /**
     * Per supply × branch for a date range: opening balance, received, used by shipments (net of
     * void returns), adjustments and closing balance — all derived from the movement ledger.
     */
    public function report(Request $request)
    {
        [$from, $to, $rows] = $this->reportRows($request);

        return response()->json(['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'rows' => $rows]);
    }

    public function reportExport(Request $request)
    {
        [$from, $to, $rows] = $this->reportRows($request);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Supply Stock');
        $sheet->fromArray([["รายงาน Stock วัสดุห่อ {$from->toDateString()} ถึง {$to->toDateString()}"]], null, 'A1');
        $header = ['สาขา', 'วัสดุ', 'ยกมา', 'รับเข้า', 'ใช้กับ Shipment', 'คืนจาก Void', 'ปรับยอด', 'คงเหลือ', 'Min', 'Max', 'สถานะ'];
        $sheet->fromArray([$header], null, 'A3');
        $sheet->getStyle('A3:K3')->getFont()->setBold(true);
        $levels = ['low' => 'ต่ำกว่า Min', 'over' => 'เกิน Max', 'ok' => 'ปกติ'];
        $sheet->fromArray($rows->map(fn ($r) => [
            $r['branch_name'], $r['supply_name'], $r['opening'], $r['received'], -$r['used'], $r['returned'], $r['adjusted'], $r['closing'],
            $r['min_qty'], $r['max_qty'], $levels[$r['level']] ?? '',
        ])->all(), null, 'A4', true);
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'supply-stock-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($spreadsheet))->save('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Collection} */
    private function reportRows(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['date_from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['date_to'] ?? now())->endOfDay();
        $branchIds = $this->branchIds($request, $data['branch_id'] ?? null);

        $opening = SupplyStockMovement::whereIn('branch_id', $branchIds)->where('created_at', '<', $from)
            ->groupBy('supply_id', 'branch_id')->selectRaw('supply_id, branch_id, SUM(quantity) as qty')->get()
            ->keyBy(fn ($r) => "{$r->supply_id}-{$r->branch_id}");
        $period = SupplyStockMovement::whereIn('branch_id', $branchIds)->whereBetween('created_at', [$from, $to])
            ->groupBy('supply_id', 'branch_id', 'type')->selectRaw('supply_id, branch_id, type, SUM(quantity) as qty')->get()
            ->groupBy(fn ($r) => "{$r->supply_id}-{$r->branch_id}");
        $stocks = SupplyStock::whereIn('branch_id', $branchIds)->get()->keyBy(fn ($s) => "{$s->supply_id}-{$s->branch_id}");

        $supplies = Supply::orderBy('name')->get(['id', 'name']);
        $branches = Branch::whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name', 'code']);
        $rows = collect();
        foreach ($branches as $branch) {
            foreach ($supplies as $supply) {
                $key = "{$supply->id}-{$branch->id}";
                if (! $opening->has($key) && ! $period->has($key) && ! $stocks->has($key)) {
                    continue;
                }
                $byType = ($period->get($key) ?? collect())->pluck('qty', 'type');
                $open = (int) ($opening->get($key)?->qty ?? 0);
                $row = [
                    'supply_id' => $supply->id,
                    'supply_name' => $supply->name,
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'opening' => $open,
                    'received' => (int) ($byType['receive'] ?? 0),
                    'used' => (int) ($byType['shipment'] ?? 0),
                    'returned' => (int) ($byType['shipment_return'] ?? 0),
                    'adjusted' => (int) ($byType['adjust'] ?? 0),
                    'min_qty' => $stocks->get($key)?->min_qty,
                    'max_qty' => $stocks->get($key)?->max_qty,
                ];
                $row['closing'] = $open + $row['received'] + $row['used'] + $row['returned'] + $row['adjusted'];
                $row['level'] = (new SupplyStock(['quantity' => $row['closing'], 'min_qty' => $row['min_qty'], 'max_qty' => $row['max_qty']]))->level;
                $rows->push($row);
            }
        }

        return [$from, $to, $rows];
    }

    private function lineRules(): array
    {
        return [
            'supply_id' => ['required', 'integer', 'exists:supplies,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function attrs(Request $request, array $data): array
    {
        return ['reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'user_id' => $request->user()->id];
    }

    private function branches(Request $request): Collection
    {
        $user = $request->user();
        $query = Branch::orderBy('name');
        if (! ($this->access->scope($user, 'supply_stock') === 'all' || $user->can_access_all_branches || $this->access->resolve($user)['is_super_admin'])) {
            $query->whereIn('id', $user->branches()->pluck('branches.id'));
        }

        return $query->get(['id', 'name', 'code']);
    }

    /** Visible branch ids, optionally narrowed to one (which must itself be visible). */
    private function branchIds(Request $request, ?int $only): array
    {
        $ids = $this->branches($request)->pluck('id')->all();

        return $only ? array_values(array_intersect($ids, [$only])) : $ids;
    }

    private function assertBranch(Request $request, int $branchId): void
    {
        abort_unless(in_array($branchId, $this->branchIds($request, null), true), 403, 'ไม่มีสิทธิ์จัดการ Stock ของสาขานี้');
    }
}
