<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            // Staff's own zone per carrier (/config/countries, Excel upload) — independent of the
            // zone the carrier's rate API reports; used to look up zone_prices.
            $table->string('ups_zone', 20)->nullable()->after('subregion');
            $table->string('dhl_zone', 20)->nullable()->after('ups_zone');
        });

        Schema::create('zone_prices', function (Blueprint $table) {
            $table->id();
            $table->string('carrier', 10);
            $table->string('charge_code', 50);
            // Exactly one of zone / country_iso2: a zone-wide price, or a single country that
            // differs from the rest of its zone (country rows win — see ZonePrice::lookup()).
            $table->string('zone', 20)->nullable();
            $table->string('country_iso2', 2)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['carrier', 'charge_code', 'zone', 'country_iso2']);
            $table->index(['carrier', 'charge_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zone_prices');
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['ups_zone', 'dhl_zone']);
        });
    }
};
