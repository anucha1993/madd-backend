<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DHL Express can only be told about a cancelled waybill by a person — records when staff told
 * DHL and whom (ShipmentController::markCarrierCancelNotified). agent_accounts.cancel_notify_emails
 * was for an auto-email that was dropped again (see the drop_cancel_notify_emails migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->string('cancel_notify_emails', 500)->nullable()->after('is_api_enabled');
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('carrier_cancel_requested_at')->nullable()->after('carrier_cancel_status');
            $table->string('carrier_cancel_requested_to', 500)->nullable()->after('carrier_cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['carrier_cancel_requested_at', 'carrier_cancel_requested_to']);
        });
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->dropColumn('cancel_notify_emails');
        });
    }
};
