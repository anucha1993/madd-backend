<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // "FORM" = staff typed free-form Commercial Invoice line items below (used to build
            // DHL's exportDeclaration.lineItems); "UPLOAD" = staff attached their own already-made
            // invoice file instead (commercial_invoice_storage_key is overridden with it), and the
            // carrier's mandatory customs data falls back to the existing per-package derivation.
            $table->string('invoice_mode')->default('FORM')->after('addon_lines');
            // Free-form, NOT tied 1:1 to physical packages — one shipment can declare several
            // product lines regardless of how many boxes it's split across (real invoices
            // usually list products, not boxes). Each: description, quantity, unit_value,
            // country_of_origin, hs_code.
            $table->json('invoice_lines')->nullable()->after('invoice_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['invoice_mode', 'invoice_lines']);
        });
    }
};
