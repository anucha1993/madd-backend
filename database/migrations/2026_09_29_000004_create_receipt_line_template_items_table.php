<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `formula` is null for a plain manual-entry line (staff types the amount by hand, e.g.
     * "FREIGHT CHARGE"); when set, it's an expression like "{FREIGHT CHARGE} * 12%" referencing
     * an EARLIER line in the same template by its description (case-insensitive, curly braces) —
     * evaluated client-side only (see madd-frontend/src/lib/formulaEval.ts), never persisted onto
     * the actual issued Receipt/ReceiptLine (those only ever store the final computed amount).
     */
    public function up(): void
    {
        Schema::create('receipt_line_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_line_template_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('formula', 500)->nullable();
            $table->boolean('is_non_vat')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_line_template_items');
    }
};
