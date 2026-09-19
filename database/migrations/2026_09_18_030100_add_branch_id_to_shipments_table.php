<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Which office actually booked this shipment — drives the header info (company
            // name/address/tax id/branch code) printed on any Receipt/Tax Invoice issued for it.
            // Nullable: older shipments booked before this column existed have no branch on record.
            $table->foreignId('branch_id')->nullable()->after('agent_account_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
