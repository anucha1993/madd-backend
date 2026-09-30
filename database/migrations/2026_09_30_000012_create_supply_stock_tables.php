<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Packing Supplies stock per branch (see App\Services\SupplyStockService).
 * - supply_stocks: current balance + Min/Max per (supply, branch). `quantity` may go negative —
 *   booking never blocks on stock, it only warns (low-stock alerts via Min).
 * - supply_stock_movements: the ledger every balance change goes through — receive (รับเข้า),
 *   adjust (ปรับยอดหลังนับจริง), shipment (ตัดจากการจอง, negative), shipment_return (คืนเมื่อ
 *   Void / ลบ Shipment Test). The report derives opening/closing balances from it.
 *
 * Also grants the new `supply_stock.*` permissions to the seeded roles.
 */
return new class extends Migration
{
    private const GRANTS = [
        'branch_manager' => [['supply_stock.view', 'supply_stock.receive', 'supply_stock.settings', 'supply_stock.report'], 'branch'],
        'staff' => [['supply_stock.view'], 'branch'],
        'accounting' => [['supply_stock.view', 'supply_stock.report'], 'all'],
    ];

    public function up(): void
    {
        Schema::create('supply_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('min_qty')->nullable();
            $table->unsignedInteger('max_qty')->nullable();
            $table->timestamps();
            $table->unique(['supply_id', 'branch_id']);
        });

        Schema::create('supply_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['branch_id', 'created_at']);
            $table->index(['supply_id', 'branch_id', 'created_at']);
        });

        foreach (self::GRANTS as $key => [$grants, $scope]) {
            $role = DB::table('roles')->where('key', $key)->first();
            if (! $role) {
                continue;
            }
            $permissions = json_decode($role->permissions, true) ?: [];
            $scopes = json_decode($role->data_scopes, true) ?: [];
            $scopes['supply_stock'] ??= $scope;
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$permissions, ...$grants]))),
                'data_scopes' => json_encode($scopes),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_stock_movements');
        Schema::dropIfExists('supply_stocks');
    }
};
