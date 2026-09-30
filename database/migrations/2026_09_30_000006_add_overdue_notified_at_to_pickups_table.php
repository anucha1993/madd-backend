<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the "courier hasn't come" email went out (see NotifyOverduePickups) — once per pickup. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });
    }
};
