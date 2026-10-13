<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_lots', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('item_id');
            $table->foreignId('receipt_detail_id')->unique();
            $table->string('lot_number', 100);
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('unit_cost_cents');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'stock_lot_scope_id');
            $table->foreign(['tenant_id', 'item_id'], 'stock_lot_item_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign('receipt_detail_id')->references('id')
                ->on('stock_receipt_details')->restrictOnDelete();
            $table->index(['tenant_id', 'item_id', 'expires_on'], 'stock_lot_fefo_index');
        });

        Schema::create('stock_lot_balances', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('lot_id');
            $table->foreignId('location_id');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id','lot_id','location_id'], 'stock_lot_position_unique');
            $table->foreign(['tenant_id','lot_id'], 'stock_lot_balance_lot_fk')
                ->references(['tenant_id','id'])->on('stock_lots')->restrictOnDelete();
            $table->foreign(['tenant_id','location_id'], 'stock_lot_balance_location_fk')
                ->references(['tenant_id','id'])->on('stock_locations')->restrictOnDelete();
        });

        Schema::create('stock_movement_lot_allocations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('movement_id');
            $table->foreignId('lot_id');
            $table->unsignedBigInteger('quantity');
            $table->timestamps();
            $table->unique(['tenant_id','movement_id','lot_id'], 'stock_movement_lot_unique');
            $table->foreign(['tenant_id','movement_id'], 'stock_allocation_movement_fk')
                ->references(['tenant_id','id'])->on('stock_movements')->restrictOnDelete();
            $table->foreign(['tenant_id','lot_id'], 'stock_allocation_lot_fk')
                ->references(['tenant_id','id'])->on('stock_lots')->restrictOnDelete();
        });

        Schema::create('stock_shipment_lot_transit', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('shipment_id');
            $table->foreignId('lot_id');
            $table->unsignedBigInteger('shipped_quantity')->default(0);
            $table->unsignedBigInteger('received_quantity')->default(0);
            $table->unsignedBigInteger('lost_quantity')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id','shipment_id','lot_id'], 'stock_transit_lot_unique');
            $table->foreign(['tenant_id','shipment_id'], 'stock_transit_shipment_fk')
                ->references(['tenant_id','id'])->on('stock_shipments')->restrictOnDelete();
            $table->foreign(['tenant_id','lot_id'], 'stock_transit_lot_fk')
                ->references(['tenant_id','id'])->on('stock_lots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_shipment_lot_transit');
        Schema::dropIfExists('stock_movement_lot_allocations');
        Schema::dropIfExists('stock_lot_balances');
        Schema::dropIfExists('stock_lots');
    }
};
