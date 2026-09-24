<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Fills shipment_total_snapshot (added 2026-09-24, null for pre-existing documents) on every
    // legacy receipt so the variance display/edit-page comparison works for them too — computed
    // from the same shipments.order_total sum that store() uses for new documents.
    public function up(): void
    {
        DB::table('receipts')
            ->whereNull('shipment_total_snapshot')
            ->orderBy('id')
            ->chunkById(200, function ($receipts) {
                foreach ($receipts as $receipt) {
                    $total = DB::table('receipt_shipment')
                        ->join('shipments', 'shipments.id', '=', 'receipt_shipment.shipment_id')
                        ->where('receipt_shipment.receipt_id', $receipt->id)
                        ->sum('shipments.order_total');

                    if ($total > 0) {
                        DB::table('receipts')->where('id', $receipt->id)->update([
                            'shipment_total_snapshot' => round((float) $total, 2),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data-only backfill of a nullable column; no safe reverse (would also wipe values set by
        // real edits made after this ran) — intentionally a no-op.
    }
};
