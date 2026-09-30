<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\SupplyStock;
use App\Models\SupplyStockMovement;
use App\Models\SystemAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of supply_stocks: every balance change is one SupplyStockMovement, so the
 * ledger always adds up to the balance. Booking never blocks on stock (balances may go
 * negative); falling to/below a branch's Min raises a 'supply_low_stock' System Alert, which
 * resolves itself once the balance is back above Min.
 */
class SupplyStockService
{
    public function move(int $supplyId, int $branchId, int $delta, string $type, array $attrs = []): SupplyStockMovement
    {
        return DB::transaction(function () use ($supplyId, $branchId, $delta, $type, $attrs) {
            $stock = $this->lockedStock($supplyId, $branchId);
            $stock->update(['quantity' => $stock->quantity + $delta]);
            $movement = SupplyStockMovement::create($attrs + [
                'supply_id' => $supplyId,
                'branch_id' => $branchId,
                'type' => $type,
                'quantity' => $delta,
                'balance_after' => $stock->quantity,
            ]);
            $this->checkLevel($stock);

            return $movement;
        });
    }

    /** Stock count: records the difference between the counted and the recorded balance. */
    public function setCounted(int $supplyId, int $branchId, int $counted, array $attrs = []): ?SupplyStockMovement
    {
        return DB::transaction(function () use ($supplyId, $branchId, $counted, $attrs) {
            $delta = $counted - $this->lockedStock($supplyId, $branchId)->quantity;

            return $delta === 0 ? null : $this->move($supplyId, $branchId, $delta, 'adjust', $attrs);
        });
    }

    public function setLimits(int $supplyId, int $branchId, ?int $min, ?int $max): SupplyStock
    {
        return DB::transaction(function () use ($supplyId, $branchId, $min, $max) {
            $stock = $this->lockedStock($supplyId, $branchId);
            $stock->update(['min_qty' => $min, 'max_qty' => $max]);
            $this->checkLevel($stock);

            return $stock;
        });
    }

    /**
     * Makes the shipment's ledger lines match its Packing Supplies add-on lines: booked →
     * −quantity per supply, voided/deleted ($active false) → 0. Idempotent, so void → unvoid →
     * void stays correct. Shipments without a branch aren't tracked.
     */
    public function syncShipment(Shipment $shipment, bool $active, ?User $user = null): void
    {
        if (! $shipment->branch_id) {
            return;
        }
        $wanted = [];
        if ($active) {
            foreach ($shipment->addon_lines ?? [] as $line) {
                if (! empty($line['supply_id'])) {
                    $wanted[(int) $line['supply_id']] = ($wanted[(int) $line['supply_id']] ?? 0) - (int) round((float) $line['quantity']);
                }
            }
        }
        $current = SupplyStockMovement::where('shipment_id', $shipment->id)
            ->where('branch_id', $shipment->branch_id)
            ->groupBy('supply_id')
            ->selectRaw('supply_id, SUM(quantity) as net')
            ->pluck('net', 'supply_id');

        foreach (collect(array_keys($wanted))->merge($current->keys())->unique() as $supplyId) {
            $delta = ($wanted[$supplyId] ?? 0) - (int) ($current[$supplyId] ?? 0);
            if ($delta === 0 || ! DB::table('supplies')->where('id', $supplyId)->exists()) {
                continue;
            }
            $this->move((int) $supplyId, $shipment->branch_id, $delta, $delta < 0 ? 'shipment' : 'shipment_return', [
                'shipment_id' => $shipment->id,
                'reference' => $shipment->tracking_number,
                'user_id' => $user?->id,
            ]);
        }
    }

    private function lockedStock(int $supplyId, int $branchId): SupplyStock
    {
        SupplyStock::firstOrCreate(['supply_id' => $supplyId, 'branch_id' => $branchId]);

        return SupplyStock::where('supply_id', $supplyId)->where('branch_id', $branchId)->lockForUpdate()->first();
    }

    private function checkLevel(SupplyStock $stock): void
    {
        $stock->loadMissing('supply:id,name', 'branch:id,name');
        $message = "Stock ใกล้หมด: {$stock->supply->name} — สาขา {$stock->branch->name}";
        if ($stock->level !== 'low') {
            SystemAlert::resolveOpen('supply_low_stock', $message);

            return;
        }
        if (! SystemAlert::isOpen('supply_low_stock', $message)) {
            SystemAlert::record('supply_low_stock', $message, array_filter([
                'supply_id' => $stock->supply_id,
                'branch_id' => $stock->branch_id,
                'quantity' => $stock->quantity,
                'min_qty' => $stock->min_qty,
                'max_qty' => $stock->max_qty,
                'suggested_order' => $stock->max_qty !== null ? max(0, $stock->max_qty - $stock->quantity) : null,
            ], fn ($v) => $v !== null), 'warning');
        }
    }
}
