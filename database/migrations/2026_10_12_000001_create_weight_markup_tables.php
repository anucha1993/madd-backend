<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weight Markup: an extra THB per chargeable kg added on top of every sell price (after Fixed
     * Charges + Mark-up rules), by weight band — per carrier × document/box, optionally per account,
     * with zone / country overrides. Bands change often, so they're data, not formulas.
     */
    public function up(): void
    {
        Schema::create('weight_markup_bands', function (Blueprint $table) {
            $table->id();
            $table->string('carrier', 10);
            $table->string('package_type', 10); // document | box
            // null = every account of the carrier; set = this account's own band set instead.
            $table->foreignId('agent_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('min_weight', 8, 2);
            $table->decimal('max_weight', 8, 2)->nullable(); // null = and above
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['carrier', 'package_type', 'agent_account_id']);
        });

        Schema::create('weight_markup_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weight_markup_band_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 10); // all | zone | country
            $table->string('scope_value', 10)->nullable(); // zone or ISO2; null for "all"
            $table->decimal('rate_per_kg', 10, 2);
            $table->timestamps();

            $table->unique(['weight_markup_band_id', 'scope_type', 'scope_value'], 'weight_markup_rates_scope_unique');
        });

        // The business's standard bands, carrier-wide, with no rate yet — prices don't move until
        // someone fills a rate in.
        $bands = [
            ['UPS', 'document', [[0.10, 5.00]]],
            ['UPS', 'box', [[0.10, 5.00], [5.01, 10.00], [10.01, 20.00], [20.01, 44.00], [44.01, 70.00], [70.01, 99.00], [99.01, 299.00], [299.01, null]]],
            ['DHL', 'document', [[0.01, 2.00]]],
            ['DHL', 'box', [[0.10, 5.00], [5.01, 10.00], [10.01, 30.00], [30.01, 70.00], [70.01, 299.00], [299.01, null]]],
        ];
        $now = now();
        foreach ($bands as [$carrier, $type, $ranges]) {
            foreach ($ranges as $i => [$min, $max]) {
                DB::table('weight_markup_bands')->insert([
                    'carrier' => $carrier, 'package_type' => $type, 'agent_account_id' => null,
                    'min_weight' => $min, 'max_weight' => $max, 'sort_order' => $i,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_markup_rates');
        Schema::dropIfExists('weight_markup_bands');
    }
};
