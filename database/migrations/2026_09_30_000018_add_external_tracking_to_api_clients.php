<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an API key also track UPS / DHL numbers that were NOT booked in MADD (e.g. shipments
 * from before MADD, or booked on the carrier's own site) using our carrier accounts. Off by
 * default and capped per day, since every such look-up spends our carrier API quota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->boolean('track_any_number')->default(false)->after('allow_tracking');
            $table->unsignedInteger('external_tracking_daily_limit')->default(500)->after('track_any_number');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', fn (Blueprint $table) => $table->dropColumn(['track_any_number', 'external_tracking_daily_limit']));
    }
};
