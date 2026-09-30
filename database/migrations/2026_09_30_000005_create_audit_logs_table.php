<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what (see App\Models\Concerns\Auditable / App\Services\AuditLogger) — roles &
 * permissions, users, pricing config, carrier accounts, shipments, receipts, pickups.
 * Append-only; viewable by `user.audit` holders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // "created" / "updated" / "deleted", or a domain action such as "roles_changed".
            $table->string('event', 50);
            $table->string('subject_type', 100)->index();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Human-readable name of the subject at the time (tracking no., role name, ...).
            $table->string('subject_label')->nullable();
            // {field: {old, new}} — secrets are always masked.
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
