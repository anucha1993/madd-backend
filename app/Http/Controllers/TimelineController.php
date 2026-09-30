<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Pickup;
use App\Models\Receipt;
use App\Models\Shipment;
use App\Models\SupplyStockMovement;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Per-record history (oldest first) for the Shipment / Receipt / Pickup pages, behind the
 * `<module>.timeline` permissions; the record itself is scope-checked by `record.scope`.
 * Entries follow the viewer's access: related records' entries need that module's `view` (and
 * are limited to records in scope), changes to field groups the viewer can't see are removed,
 * and IPs are only shown to `user.audit` holders.
 */
class TimelineController extends Controller
{
    private const MODULES = ['Shipment' => 'shipment', 'Receipt' => 'receipt', 'Pickup' => 'pickup'];

    public function __construct(private AccessService $access) {}

    public function shipment(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $subjects = [['Shipment', [$shipment->id]]];
        if ($this->access->can($user, 'pickup.view')) {
            $subjects[] = ['Pickup', Pickup::visibleTo($user)->whereHas('shipments', fn ($q) => $q->whereKey($shipment->id))->pluck('id')->all()];
        }
        if ($this->access->can($user, 'receipt.view')) {
            $subjects[] = ['Receipt', Receipt::visibleTo($user)->whereHas('shipments', fn ($q) => $q->whereKey($shipment->id))->pluck('id')->all()];
        }

        $entries = $this->auditEntries($user, $subjects);
        if ($this->access->can($user, 'supply_stock.view')) {
            $entries = $entries->concat(
                SupplyStockMovement::with('supply:id,name', 'branch:id,name', 'user:id,name')->where('shipment_id', $shipment->id)->get()
                    ->map(fn (SupplyStockMovement $m) => [
                        'id' => "stock-{$m->id}",
                        'at' => $m->created_at?->toIso8601String(),
                        'source' => 'Stock',
                        'source_id' => $m->supply_id,
                        'event' => "stock_{$m->type}",
                        'label' => trim(($m->supply?->name ?? '').' — '.($m->branch?->name ?? ''), ' —'),
                        'actor' => $m->user?->name,
                        'is_system' => ! $m->user_id,
                        'changes' => ['quantity' => ['old' => null, 'new' => $m->quantity], 'balance_after' => ['old' => null, 'new' => $m->balance_after]],
                    ]),
            );
        }

        return response()->json(['entries' => $this->sorted($entries)]);
    }

    public function receipt(Request $request, Receipt $receipt)
    {
        return response()->json(['entries' => $this->sorted($this->auditEntries($request->user(), [['Receipt', [$receipt->id]]]))]);
    }

    /** The pickup's own history plus its shipments' collection (picked up) updates. */
    public function pickup(Request $request, Pickup $pickup)
    {
        $user = $request->user();
        $entries = $this->auditEntries($user, [['Pickup', [$pickup->id]]]);
        if ($this->access->can($user, 'shipment.view')) {
            $shipmentIds = Shipment::visibleTo($user)->whereHas('pickups', fn ($q) => $q->whereKey($pickup->id))->pluck('shipments.id')->all();
            $entries = $entries->concat(
                $this->auditEntries($user, [['Shipment', $shipmentIds]])
                    ->filter(fn ($e) => $e['event'] === 'updated' && isset($e['changes']['picked_up_at']))
                    ->map(fn ($e) => ['changes' => array_intersect_key($e['changes'], array_flip(['picked_up_at', 'picked_up_source']))] + $e),
            );
        }

        return response()->json(['entries' => $this->sorted($entries)]);
    }

    /** @param  array<int, array{0: string, 1: array<int, int>}>  $subjects */
    private function auditEntries(User $user, array $subjects): Collection
    {
        $subjects = array_filter($subjects, fn ($s) => $s[1]);
        if (! $subjects) {
            return collect();
        }
        $showIp = $this->access->can($user, 'user.audit');
        $hidden = collect(self::MODULES)->map(fn ($module) => array_flip($this->access->hiddenColumns($user, $module)));

        return AuditLog::with('user:id,name')
            ->where(function ($q) use ($subjects) {
                foreach ($subjects as [$type, $ids]) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->get()
            ->map(function (AuditLog $log) use ($hidden, $showIp) {
                $changes = array_diff_key($log->changes ?? [], $hidden[$log->subject_type] ?? []);
                if ($log->event === 'updated' && ! $changes) {
                    return null; // only hidden fields changed
                }

                return [
                    'id' => "audit-{$log->id}",
                    'at' => $log->created_at?->toIso8601String(),
                    'source' => $log->subject_type,
                    'source_id' => $log->subject_id,
                    'event' => $log->event,
                    'label' => $log->subject_label,
                    'actor' => $log->user?->name,
                    'is_system' => ! $log->user_id,
                    'changes' => $changes ?: null,
                ] + ($showIp ? ['ip' => $log->ip] : []);
            })
            ->filter()
            ->values();
    }

    private function sorted(Collection $entries): array
    {
        return $entries->sortBy([['at', 'asc'], ['id', 'asc']])->values()->all();
    }
}
