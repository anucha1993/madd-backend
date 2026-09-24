<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Only 'manifest' is implemented today — kept as a string (not an enum) so future
            // report types can be added without a migration.
            $table->string('report_type')->default('manifest');
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            // Time of day to send, e.g. "08:00".
            $table->string('send_time', 5);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            // Which shipments to include in the generated report each time it's sent — same
            // daily/weekly/monthly/yearly presets as the on-screen /manifest page.
            $table->string('report_range')->default('daily');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier')->nullable();
            $table->foreignId('agent_account_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_sent_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
