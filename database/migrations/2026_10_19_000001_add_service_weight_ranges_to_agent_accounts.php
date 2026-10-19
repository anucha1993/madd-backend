<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Check Rate weight range per service (e.g. UPS 08 Expedited only for 10.01–70 kg); a service not
// listed keeps the account's own range. Additive only: null = every service uses that range.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->json('service_weight_ranges')->nullable()->after('rate_max_weight');
        });
    }

    public function down(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->dropColumn('service_weight_ranges');
        });
    }
};
