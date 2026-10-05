<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_invoice_lines', function (Blueprint $table) {
            $table->decimal('charges', 14, 2)->nullable()->after('description');
            $table->decimal('discount', 14, 2)->nullable()->after('charges');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['charges', 'discount']);
        });
    }
};
