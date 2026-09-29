<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The exact request body we sent to UPS/DHL when booking — kept alongside raw_response as
     * dispute evidence (e.g. proving we requested BillReceiver but the carrier billed it wrong).
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->json('raw_request')->nullable()->after('raw_response');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('raw_request');
        });
    }
};
