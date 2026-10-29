<?php

use App\Services\HsCodeImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HS / HTS codes for the Commercial Invoice HS Code lookup on /shipment/create, managed at
 * /config/hs-codes. Starts with CPSC's eFiling HTS list (January 2026, ~590 codes) bundled in
 * database/data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('code_formatted', 30);
            $table->string('description');
            $table->string('chapter', 50)->nullable();
            $table->string('source', 100)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique(['code', 'description']);
            $table->index('code');
        });

        $file = database_path('data/CPSC_HTS_List_eFiling_Jan2026.xlsx');
        if (is_file($file) && ! app()->runningUnitTests()) {
            app(HsCodeImporter::class)->import($file, 'CPSC eFiling HTS List (Jan 2026)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_codes');
    }
};
