<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One pickup request can cover MULTIPLE shipments (courier picks up everything ready at
        // the address at once) — confirmed live against both carriers: neither UPS's
        // PickupCreationRequest nor DHL's Create Pickup request accepts/needs a list of specific
        // tracking numbers at all, only address + total piece count/weight + ready/close time.
        // The courier scans whatever boxes are actually handed over on pickup, independent of
        // which shipments we say are included here — this table is our OWN bookkeeping only.
        Schema::create('pickups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('carrier'); // UPS or DHL
            $table->string('status')->default('requested'); // requested, cancelled, failed
            $table->date('pickup_date');
            $table->string('ready_time'); // "HH:mm" 24hr, converted to each carrier's own format
            $table->string('close_time');
            $table->json('address');
            $table->decimal('total_weight', 10, 2)->default(0);
            $table->unsignedInteger('total_pieces')->default(0);
            // PRN (UPS) or dispatchConfirmationNumber (DHL) — the carrier's own reference for
            // this pickup, needed to cancel it later.
            $table->string('carrier_reference')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pickup_shipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pickup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_shipment');
        Schema::dropIfExists('pickups');
    }
};
