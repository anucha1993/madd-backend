<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Optional label staff can set to tell drafts apart in the list (e.g. "ส่งให้ John SG") —
            // falls back to destination contact name/city if left blank (see ShipmentDraftController).
            $table->string('name')->nullable();
            // Full serialized /shipment/create form state (ship info, packages, addon rows, selected
            // rate quote, payment info, etc.) — restored as-is when staff resumes editing this draft.
            $table->json('form_state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_drafts');
    }
};
