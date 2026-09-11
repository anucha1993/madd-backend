<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_carrier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_account_id')->constrained()->cascadeOnDelete();
            // Distinguishes multiple accounts of the same carrier under one branch, e.g. "Package", "Document".
            $table->string('label')->nullable();
            // Prefix used when generating tracking numbers for this branch/account, e.g. "1ZAX3173".
            $table->string('tracking_prefix')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'agent_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_carrier_accounts');
    }
};
