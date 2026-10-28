<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Over-the-counter sales of boxes / Packing Supplies (no shipment) — printed on an 80 mm thermal
 * receipt, separate from Cash Receipt / Tax Invoice. Each sale deducts the selling branch's stock
 * (supply_stock_movements type 'sale'; voiding returns it as 'sale_return').
 *
 * Also grants the new `supply_sale.*` permissions to the seeded roles.
 */
return new class extends Migration
{
    private const GRANTS = [
        'branch_manager' => [['supply_sale.view', 'supply_sale.create', 'supply_sale.print', 'supply_sale.void'], 'branch'],
        'staff' => [['supply_sale.view', 'supply_sale.create', 'supply_sale.print'], 'branch'],
        'cs' => [['supply_sale.view', 'supply_sale.create', 'supply_sale.print'], 'branch'],
        'accounting' => [['supply_sale.view', 'supply_sale.print'], 'all'],
    ];

    public function up(): void
    {
        Schema::create('supply_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('sale_no', 50);
            $table->timestamp('sold_at');
            $table->string('customer_name')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('total', 12, 2);
            $table->string('status', 20)->default('ISSUED');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'sale_no']);
            $table->index(['branch_id', 'sold_at']);
        });

        Schema::create('supply_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
        });

        Schema::table('supply_stock_movements', function (Blueprint $table) {
            $table->foreignId('supply_sale_id')->nullable()->after('shipment_id')->constrained()->nullOnDelete();
        });

        foreach (self::GRANTS as $key => [$grants, $scope]) {
            $role = DB::table('roles')->where('key', $key)->first();
            if (! $role) {
                continue;
            }
            $permissions = json_decode($role->permissions, true) ?: [];
            $scopes = json_decode($role->data_scopes, true) ?: [];
            $scopes['supply_sale'] ??= $scope;
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$permissions, ...$grants]))),
                'data_scopes' => json_encode($scopes),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('supply_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_sale_id');
        });
        Schema::dropIfExists('supply_sale_lines');
        Schema::dropIfExists('supply_sales');
    }
};
