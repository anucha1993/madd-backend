<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Which side of a shipment this saved address can be used for — a customer may have
            // several pickup locations (ship_from) and/or several regular recipients (ship_to).
            $table->enum('type', ['ship_from', 'ship_to', 'both'])->default('both');
            // Short name shown in the picker, e.g. "Warehouse A", "สาขาสีลม" — optional, falls
            // back to contact_name/address1 in the UI when blank.
            $table->string('label')->nullable();
            $table->string('contact_name');
            $table->string('company_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state_code')->nullable();
            $table->string('postcode')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('address3')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
