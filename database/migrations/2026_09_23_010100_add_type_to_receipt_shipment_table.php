<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('receipt_shipment', 'type')) {
            Schema::table('receipt_shipment', function (Blueprint $table) {
                // Denormalized snapshot of the parent receipt's type — lets the lock be enforced PER
                // TYPE below instead of globally, since a shipment now always belongs to exactly one
                // CASH_RECEIPT lock AND one TAX_INVOICE lock (the paired-issuance model), rather than
                // just one receipt total.
                $table->string('type', 20)->nullable()->after('shipment_id');
            });
        }

        // Backfill existing rows from their parent receipt (portable across DB drivers).
        DB::table('receipt_shipment')->whereNull('type')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $type = DB::table('receipts')->where('id', $row->receipt_id)->value('type');
                if ($type) {
                    DB::table('receipt_shipment')->where('id', $row->id)->update(['type' => $type]);
                }
            }
        });

        // Add the new composite unique index BEFORE dropping the old single-column one — MySQL
        // requires the `shipment_id` foreign key to always have a covering index available, and
        // since `shipment_id` is the leftmost column of the composite index, it satisfies that
        // requirement, allowing the old unique to be dropped cleanly afterwards.
        if (! $this->indexExists('receipt_shipment', 'receipt_shipment_shipment_id_type_unique')) {
            Schema::table('receipt_shipment', function (Blueprint $table) {
                $table->unique(['shipment_id', 'type']);
            });
        }

        if ($this->indexExists('receipt_shipment', 'receipt_shipment_shipment_id_unique')) {
            Schema::table('receipt_shipment', function (Blueprint $table) {
                $table->dropUnique(['shipment_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('receipt_shipment', function (Blueprint $table) {
            $table->unique('shipment_id');
            $table->dropUnique(['shipment_id', 'type']);
            $table->dropColumn('type');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
