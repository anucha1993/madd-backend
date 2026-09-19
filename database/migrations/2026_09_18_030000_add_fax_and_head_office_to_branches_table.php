<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Printed on the Receipt page next to phone (Tax Invoice page never shows fax).
            $table->string('fax')->nullable()->after('phone');
            // Exactly one branch is normally flagged head office — its address/phone/fax is
            // always printed as the fixed "สำนักงานใหญ่" block on receipts/tax invoices, in
            // addition to the specific issuing branch's own "สำนักงานสาขา" block.
            $table->boolean('is_head_office')->default(false)->after('fax');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['fax', 'is_head_office']);
        });
    }
};
