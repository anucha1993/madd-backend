<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rate Book: a periodic snapshot of our own SELL price (carrier rate API + per-account
     * overrides/markup, same as Create Shipment) per carrier × document/box × weight × zone,
     * exported as an internal Excel price list. One run = one full snapshot.
     */
    public function up(): void
    {
        Schema::create('rate_book_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('running'); // running | success | partial | failed
            $table->string('trigger', 20); // schedule | manual
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_points')->default(0);
            $table->unsignedInteger('done_points')->default(0);
            $table->unsignedInteger('error_points')->default(0);
            $table->text('error')->nullable();
            // The settings this run was built from (accounts, bands, zone countries, VAT %).
            $table->json('settings')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rate_book_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_book_run_id')->constrained()->cascadeOnDelete();
            $table->string('carrier', 10);
            $table->string('package_type', 10); // document | box
            $table->string('zone', 20);
            $table->string('country_iso2', 2);
            $table->string('band_label', 30);
            // Weight actually quoted. Per-kg rows quote one representative weight in the band and
            // store every amount divided by it.
            $table->decimal('weight', 8, 2);
            $table->boolean('is_per_kg')->default(false);
            $table->foreignId('agent_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_username', 50)->nullable();
            $table->string('service_code', 10)->nullable();
            foreach (['freight', 'fuel', 'surge', 'remote', 'peak', 'gogreen', 'other', 'markup', 'sell', 'vat', 'total'] as $column) {
                $table->decimal($column, 12, 2)->nullable();
            }
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['rate_book_run_id', 'carrier', 'package_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_book_rows');
        Schema::dropIfExists('rate_book_runs');
    }
};
