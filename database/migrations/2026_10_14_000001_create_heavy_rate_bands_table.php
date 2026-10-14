<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Heavy Weight sell rates (/config/heavy-rates): from a set weight (71 kg) up, the sell price is
     * staff's own THB per kg — by weight band × zone — instead of the carrier's priced quote. rates =
     * {"all": 150, "zone:5": 160}. Seeded with the standard bands and no prices, so nothing changes
     * until a price is filled in.
     */
    public function up(): void
    {
        Schema::create('heavy_rate_bands', function (Blueprint $table) {
            $table->id();
            $table->string('carrier', 10);
            $table->decimal('min_weight', 8, 2);
            $table->decimal('max_weight', 8, 2)->nullable(); // null = and above
            $table->json('rates');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('carrier');
        });

        $now = now();
        foreach (['UPS', 'DHL'] as $carrier) {
            foreach ([[71, 299], [300, null]] as $i => [$min, $max]) {
                DB::table('heavy_rate_bands')->insert([
                    'carrier' => $carrier, 'min_weight' => $min, 'max_weight' => $max, 'rates' => '{}',
                    'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('heavy_rate_bands');
    }
};
