<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('carrier'); // UPS or DHL
            $table->string('service_code');
            $table->string('service_label')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status')->default('pending'); // pending, booked, failed
            $table->json('origin');
            $table->json('destination');
            $table->json('packages');
            $table->json('addon_lines')->nullable();
            $table->decimal('freight_amount', 12, 2)->default(0);
            $table->decimal('addon_total', 12, 2)->default(0);
            $table->decimal('order_total', 12, 2)->default(0);
            $table->string('currency', 3)->default('THB');
            // Label file is stored in Google Drive (see GoogleDriveService) — never a public URL,
            // always fetched through our own authenticated download route.
            $table->string('label_drive_file_id')->nullable();
            $table->string('label_view_link')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
