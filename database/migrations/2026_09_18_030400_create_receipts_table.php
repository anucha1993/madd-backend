<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['CASH_RECEIPT', 'TAX_INVOICE']);
            // Snapshot of the issuing office — every shipment attached must share this branch.
            $table->foreignId('branch_id')->constrained();
            $table->string('vol_no');
            $table->string('no');
            $table->date('issued_date');

            // Buyer fields are SNAPSHOTTED at issue time (legal document must stay immutable even
            // if billing_customers/customer_addresses data changes later). billing_customer_id is
            // only kept as a convenience back-reference, never re-read for display.
            $table->foreignId('billing_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buyer_name');
            $table->string('buyer_tax_id')->nullable();
            $table->text('buyer_address')->nullable();
            $table->boolean('buyer_is_head_office')->default(true);
            $table->string('buyer_branch_no')->nullable();

            $table->decimal('subtotal_non_vat', 12, 2)->default(0);
            $table->decimal('subtotal_vat', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(7.00);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->string('grand_total_words')->nullable();

            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();

            $table->enum('status', ['ISSUED', 'VOIDED'])->default('ISSUED');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
