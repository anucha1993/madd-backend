<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->renameColumn('label_drive_file_id', 'label_storage_key');
            $table->dropColumn('label_view_link');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->renameColumn('label_storage_key', 'label_drive_file_id');
            $table->string('label_view_link')->nullable();
        });
    }
};
