<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifest_options', function (Blueprint $table) {
            // null = applies to both carriers (e.g. Payment Option, Zone); some options
            // (e.g. Customer Type "Shop CR" = CR on UPS, SCR on DHL) need a per-carrier code.
            $table->string('provider', 10)->nullable()->after('group');
            // fixed baht amount for line items that are a flat fee rather than a lookup code
            // (e.g. Manifest "Form"/"OT" charges: FORM=535, OT=214 on UPS, FORM=343 on DHL).
            $table->decimal('amount', 10, 2)->nullable()->after('code');

            $table->dropUnique(['group', 'code']);
        });

        Schema::table('manifest_options', function (Blueprint $table) {
            $table->unique(['group', 'provider', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('manifest_options', function (Blueprint $table) {
            $table->dropUnique(['group', 'provider', 'code']);
            $table->dropColumn(['provider', 'amount']);
            $table->unique(['group', 'code']);
        });
    }
};
