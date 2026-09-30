<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Websites whose pages may call the Public Tracking API straight from the visitor's browser
 * (GET /api/public/v1/web/tracking/{number}, see AuthenticateWebOrigin) — no API key in the
 * page; the request's Origin must be listed on an active client that allows tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->json('browser_origins')->nullable()->after('allowed_ips');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', fn (Blueprint $table) => $table->dropColumn('browser_origins'));
    }
};
