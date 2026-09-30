<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Background failures an admin should see (see App\Models\SystemAlert): every exception that
 * reaches Laravel's reporter (label/invoice uploads to R2, UPS paperless, uncaught 500s) plus
 * tracking-sync / pickup-alert run failures. Repeats of the same open alert are folded into one
 * row (occurrences + last_seen_at) so a recurring fault doesn't flood the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10)->default('error');
            $table->string('source', 50);
            $table->string('message', 1000);
            $table->json('context')->nullable();
            $table->string('fingerprint', 64)->index();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
