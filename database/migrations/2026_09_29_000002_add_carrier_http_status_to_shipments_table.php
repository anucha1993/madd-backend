<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The HTTP status code the carrier returned for the booking request itself — explicit
     * confirmation (e.g. 200) that the raw_request above was actually received/accepted, without
     * having to dig through raw_response's nested structure to infer it.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedSmallInteger('carrier_http_status')->nullable()->after('raw_request');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('carrier_http_status');
        });
    }
};
