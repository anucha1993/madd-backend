<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Rate Book API (GET /api/public/v1/rate-book): the latest Rate Book's selling prices as
 * customer rate cards, for an external site. Off by default — each API key opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->boolean('allow_rate_book')->default(false)->after('allow_tracking');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', fn (Blueprint $table) => $table->dropColumn('allow_rate_book'));
    }
};
