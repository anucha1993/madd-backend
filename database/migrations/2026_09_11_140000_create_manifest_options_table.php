<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifest_options', function (Blueprint $table) {
            $table->id();
            // e.g. customer_type, payment_option, zone, destination
            $table->string('group', 50);
            $table->string('name', 100);
            // short code used when generating the Manifest Report (e.g. DAY, CR, WI)
            $table->string('code', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['group', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_options');
    }
};
