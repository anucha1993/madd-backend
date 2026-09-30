<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined table layouts (see ColumnProfileController): which columns a list page shows,
 * in what default order/stacking, and which Roles may use it. Users can only re-arrange a
 * profile's columns for themselves (browser-side) — never add or remove any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('column_profiles', function (Blueprint $table) {
            $table->id();
            // useManageColumns storageKey, e.g. "shipment-list" (see config/permissions.php column_pages).
            $table->string('page_key')->index();
            $table->string('name');
            // Visible column ids, in default display order.
            $table->json('columns');
            // Column id -> group key; same key = stacked in one cell.
            $table->json('group_of');
            // Role ids allowed to use it — empty = every role.
            $table->json('role_ids');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_profiles');
    }
};
