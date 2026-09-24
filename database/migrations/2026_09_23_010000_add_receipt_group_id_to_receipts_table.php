<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Groups a Cash Receipt + Tax Invoice issued together as ONE pair (2026-09-23: staff
            // no longer choose either/or — every issuance always creates both, each with its own
            // independent Vol.No/No. sequence, but sharing the same buyer/lines/shipments and this
            // group id so Edit/Void/Delete can cascade across the pair. Null for pre-existing
            // standalone documents issued before this change.
            $table->string('receipt_group_id')->nullable()->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('receipt_group_id');
        });
    }
};
