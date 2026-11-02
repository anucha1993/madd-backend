<?php

use App\Models\HsCode;
use App\Services\HsCodeImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the full HS 2022 nomenclature (WCO, 6-digit, ~5,600 codes) to the HS Code lookup — the
 * CPSC list alone only covered ~23 product groups. Source: UN Comtrade via the open
 * datasets/harmonized-system package (ODC Public Domain Dedication and Licence), bundled in
 * database/data.
 */
return new class extends Migration
{
    private const SOURCE = 'HS 2022 (WCO / UN Comtrade)';

    public function up(): void
    {
        $file = database_path('data/HS2022_UN_Comtrade.csv');
        if (is_file($file) && ! app()->runningUnitTests()) {
            app(HsCodeImporter::class)->import($file, self::SOURCE);
        }
    }

    public function down(): void
    {
        HsCode::where('source', self::SOURCE)->delete();
    }
};
