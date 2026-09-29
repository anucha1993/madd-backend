<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account number (Receiver/Third Party) and third-party address fields for Bill
     * Transportation to / Bill Duty and Tax to — required by UPS's BillReceiver/BillThirdParty
     * and DHL's payer/duties-taxes account entries whenever billing isn't to our own Shipper
     * account. See UpsShipmentService::buildPaymentInformation / DhlShipmentService::buildAccounts.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('bill_transportation_account_number')->nullable()->after('bill_transportation_to');
            $table->string('bill_transportation_third_party_country', 2)->nullable()->after('bill_transportation_account_number');
            $table->string('bill_transportation_third_party_postal_code')->nullable()->after('bill_transportation_third_party_country');
            $table->string('bill_duty_tax_account_number')->nullable()->after('bill_duty_tax_to');
            $table->string('bill_duty_tax_third_party_country', 2)->nullable()->after('bill_duty_tax_account_number');
            $table->string('bill_duty_tax_third_party_postal_code')->nullable()->after('bill_duty_tax_third_party_country');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'bill_transportation_account_number',
                'bill_transportation_third_party_country',
                'bill_transportation_third_party_postal_code',
                'bill_duty_tax_account_number',
                'bill_duty_tax_third_party_country',
                'bill_duty_tax_third_party_postal_code',
            ]);
        });
    }
};
