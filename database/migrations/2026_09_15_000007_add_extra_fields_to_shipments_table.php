<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Everything staff fills in on /shipment/create's Step 1 (Customer Type / Individual
            // Category) and Step 4 (Payment Info) was previously never persisted at all — only
            // origin/destination/packages/addon_lines/amounts were saved. Adding it all so the
            // "View Details" screen can show literally everything that was submitted.
            $table->string('customer_type')->nullable()->after('currency');
            $table->string('entity_type')->nullable()->after('customer_type');
            $table->string('payment_method')->nullable()->after('entity_type');
            $table->string('bill_transportation_to')->nullable()->after('payment_method');
            $table->string('bill_duty_tax_to')->nullable()->after('bill_transportation_to');
            $table->string('ref_invoice_no')->nullable()->after('bill_duty_tax_to');
            $table->string('ref_insurance_no')->nullable()->after('ref_invoice_no');
            $table->string('ref_purchase_no')->nullable()->after('ref_insurance_no');
            // Full selected Rate Quote (zone, transit days, billed weight, chargeBreakdown, raw
            // carrier response, etc.) — service_code/service_label alone weren't the whole quote.
            $table->json('rate_quote')->nullable()->after('ref_purchase_no');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type', 'entity_type', 'payment_method',
                'bill_transportation_to', 'bill_duty_tax_to',
                'ref_invoice_no', 'ref_insurance_no', 'ref_purchase_no', 'rate_quote',
            ]);
        });
    }
};
