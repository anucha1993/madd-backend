<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staff tell DHL about cancelled waybills themselves — the auto-email contact list isn't used. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agent_accounts', 'cancel_notify_emails')) {
            Schema::table('agent_accounts', function (Blueprint $table) {
                $table->dropColumn('cancel_notify_emails');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->string('cancel_notify_emails', 500)->nullable()->after('is_api_enabled');
        });
    }
};
