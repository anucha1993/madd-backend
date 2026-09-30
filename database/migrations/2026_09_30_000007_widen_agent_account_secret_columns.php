<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted carrier secrets (App\Casts\CarrierSecret) are ~300+ characters — longer than the
 * original VARCHAR(255) columns — so they need TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->text('client_secret')->nullable()->change();
            $table->text('basic_auth_password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_accounts', function (Blueprint $table) {
            $table->string('client_secret')->nullable()->change();
            $table->string('basic_auth_password')->nullable()->change();
        });
    }
};
