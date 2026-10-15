<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Images uploaded for the Customer Display (the ad banner) — kept in the database so they work
     * on any host without file storage setup, and served publicly by id (the display needs no login).
     */
    public function up(): void
    {
        Schema::create('customer_display_media', function (Blueprint $table) {
            $table->id();
            $table->string('mime', 50);
            $table->longText('data'); // base64
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_display_media');
    }
};
