<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('carrier'); // UPS | DHL
            $table->foreignId('agent_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->string('currency', 10)->default('THB');
            $table->string('storage_key');
            $table->string('original_filename')->nullable();
            // uploaded -> parsing -> parsed | failed
            $table->string('status')->default('uploaded');
            $table->longText('ocr_text')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['carrier', 'invoice_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_invoices');
    }
};
