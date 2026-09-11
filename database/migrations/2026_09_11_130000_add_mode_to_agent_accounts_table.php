<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            // Which API environment this account's credentials belong to — UPS (wwwcie.ups.com
            // vs onlinetools.ups.com) and DHL (.../mydhlapi/test vs .../mydhlapi) both require
            // hitting a completely different host depending on test vs production credentials.
            $table->enum('mode', ['test', 'production'])->default('production')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
