<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Supply;
use App\Models\SupplySale;
use App\Services\AccessService;
use App\Services\SupplySalePdfService;
use App\Services\SupplyStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Over-the-counter box / Packing Supplies sales with no shipment — its own 80 mm thermal receipt
 * (not a Cash Receipt / Tax Invoice). Selling deducts the selling branch's stock right away;
 * voiding returns it (SupplyStockService::syncSale).
 */
class SupplySaleController extends Controller
{
    public function __construct(
        private SupplyStockService $stock,
        private AccessService $access,
    ) {
    }

    public function index(Request $request)
    {
        $query = SupplySale::visibleTo($request->user())->with('branch:id,name,code,nickname', 'lines', 'createdBy:id,name')->latest('sold_at')->latest('id');

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('sale_no', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")->orWhere('customer_phone', 'like', "%{$search}%"));
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('sold_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('sold_at', '<=', $dateTo);
        }

        // Sum of the non-voided sales matching the filters (all pages) — for closing the day.
        $issuedTotal = (clone $query)->reorder()->where('status', 'ISSUED')->sum('total');
        $page = $query->paginate(min(100, max(10, (int) $request->query('per_page', 20))));

        return response()->json([...$page->toArray(), 'issued_total' => round((float) $issuedTotal, 2)]);
    }

    public function show(SupplySale $supplySale)
    {
        return $supplySale->load('branch', 'lines', 'createdBy:id,name');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            // A line without supply_id is a typed-in item (e.g. tape) — sold as named, no stock.
            'lines.*.supply_id' => ['nullable', 'integer', 'exists:supplies,id'],
            'lines.*.name' => ['required_without:lines.*.supply_id', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $isSuperAdmin = $this->access->resolve($user)['is_super_admin'];
        $allowedBranches = ShipmentController::bookingBranchIds($user)->push($user->branch_id)->filter()->map(fn ($id) => (int) $id);
        if (! $isSuperAdmin && ! $allowedBranches->contains((int) $data['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'ขายในนามสาขานี้ไม่ได้']);
        }

        $supplies = Supply::whereIn('id', collect($data['lines'])->pluck('supply_id')->filter())->get()->keyBy('id');
        $lines = collect($data['lines'])->map(fn ($line) => [
            'supply_id' => ! empty($line['supply_id']) ? (int) $line['supply_id'] : null,
            'name' => ! empty($line['supply_id']) ? $supplies[$line['supply_id']]->name : trim($line['name']),
            'quantity' => (int) $line['quantity'],
            'unit_price' => round((float) $line['unit_price'], 2),
            'amount' => round((int) $line['quantity'] * (float) $line['unit_price'], 2),
        ]);

        // Nobody sells what the branch hasn't got — Administrator included.
        if ($shortage = $this->stock->shortage($lines->all(), (int) $data['branch_id'])) {
            return response()->json(['error' => $shortage], 422);
        }

        $sale = DB::transaction(function () use ($data, $lines, $user) {
            $sale = SupplySale::create([
                'branch_id' => $data['branch_id'],
                'sale_no' => $this->nextSaleNo((int) $data['branch_id']),
                'sold_at' => now(),
                'customer_name' => $data['customer_name'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'total' => round($lines->sum('amount'), 2),
                'status' => 'ISSUED',
                'created_by' => $user->id,
            ]);
            $sale->lines()->createMany($lines->all());
            $this->stock->syncSale($sale->load('lines'), true, $user);

            return $sale;
        });

        return response()->json($sale->load('branch', 'lines'), 201);
    }

    /**
     * "SS2610-00001": year + month of the sale, then a running count per branch. The branch row is
     * locked for the rest of the transaction so two sales at once can't take the same number.
     */
    private function nextSaleNo(int $branchId): string
    {
        Branch::whereKey($branchId)->lockForUpdate()->first();
        $count = SupplySale::where('branch_id', $branchId)->count();

        return 'SS'.now('Asia/Bangkok')->format('ym').'-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    public function void(Request $request, SupplySale $supplySale)
    {
        if ($supplySale->status === 'VOIDED') {
            return response()->json(['error' => 'รายการนี้ถูกยกเลิกไปแล้ว'], 422);
        }
        $data = $request->validate(['void_note' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($supplySale, $data, $request) {
            $supplySale->update(['status' => 'VOIDED', 'voided_at' => now(), 'void_note' => $data['void_note'] ?? null]);
            $this->stock->syncSale($supplySale->load('lines'), false, $request->user());
        });

        return response()->json($supplySale->fresh()->load('branch', 'lines'));
    }

    public function pdf(SupplySale $supplySale, SupplySalePdfService $pdf)
    {
        return response($pdf->render($supplySale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$supplySale->sale_no.'.pdf"',
        ]);
    }
}
