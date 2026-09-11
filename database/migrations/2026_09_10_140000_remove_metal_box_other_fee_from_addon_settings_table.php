<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metal Box and Other are per-item price lists in the source spreadsheet (each Metal Box
        // variant has its own name+price, "Other" is a free-text remark+amount entered per
        // shipment) — they don't fit as a single flat number per carrier, so they don't belong
        // in this settings table. Metal Box/Bubble-Foam items should be added as Packaging
        // Supplies instead.
        Schema::table('addon_settings', function (Blueprint $table) {
            $table->dropColumn(['metal_box_fee', 'other_fee']);
        });
    }

    public function down(): void
    {
        Schema::table('addon_settings', function (Blueprint $table) {
            $table->decimal('metal_box_fee', 10, 2)->default(0);
            $table->decimal('other_fee', 10, 2)->default(0);
        });
    }
};
