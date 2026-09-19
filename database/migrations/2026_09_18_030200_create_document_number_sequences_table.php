<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['CASH_RECEIPT', 'TAX_INVOICE']);
            $table->enum('field', ['vol_no', 'no']);
            // e.g. "MADD-{YY}-{MM}-{00001}" or plain "{00001}" — {YY}/{YYYY}/{MM}/{DD} substitute
            // the current date, a run of zeros in braces is the auto-incrementing counter
            // zero-padded to that width (see DocumentNumberService).
            $table->string('pattern');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'document_type', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
