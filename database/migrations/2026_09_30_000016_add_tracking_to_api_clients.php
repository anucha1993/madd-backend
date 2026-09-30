<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Tracking API (GET /api/public/v1/tracking/{number}): each API key chooses which public
 * endpoints it may call, and request logs record which endpoint + the tracking number asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->boolean('allow_rates')->default(true)->after('allowed_ips');
            $table->boolean('allow_tracking')->default(true)->after('allow_rates');
        });
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->string('endpoint', 20)->default('rates')->after('api_client_id');
            $table->string('reference', 50)->nullable()->after('endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('api_request_logs', fn (Blueprint $table) => $table->dropColumn(['endpoint', 'reference']));
        Schema::table('api_clients', fn (Blueprint $table) => $table->dropColumn(['allow_rates', 'allow_tracking']));
    }
};
