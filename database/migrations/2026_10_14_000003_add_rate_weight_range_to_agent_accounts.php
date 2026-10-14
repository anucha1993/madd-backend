<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weight range an account is quoted for at Check Rate (e.g. ax3173 = 0-10 kg) — compared with the
     * shipment's chargeable weight. Both null = every weight (how every existing account keeps working).
     */
    public function up(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->decimal('rate_min_weight', 8, 2)->nullable()->after('is_api_enabled');
            $table->decimal('rate_max_weight', 8, 2)->nullable()->after('rate_min_weight');
        });
    }

    public function down(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->dropColumn(['rate_min_weight', 'rate_max_weight']);
        });
    }
};
