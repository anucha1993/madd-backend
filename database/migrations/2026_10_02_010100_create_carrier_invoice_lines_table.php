<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('tracking_number')->nullable();
            $table->string('reference_text')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_matched')->default(false);
            $table->decimal('override_amount', 14, 2)->nullable();
            $table->text('override_note')->nullable();
            $table->timestamps();

            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_invoice_lines');
    }
};
