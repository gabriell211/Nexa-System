<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_reorder_policies', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            $table->unsignedBigInteger('critical_quantity');
            $table->unsignedBigInteger('safety_quantity');
            $table->unsignedBigInteger('minimum_quantity');
            $table->unsignedBigInteger('target_quantity');
            $table->timestamps();
            $table->unique(['tenant_id', 'item_id', 'location_id'], 'stock_reorder_position_unique');
            $table->foreign(['tenant_id', 'item_id'], 'stock_reorder_item_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'location_id'], 'stock_reorder_location_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'location_id', 'item_id'], 'stock_reorder_location_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reorder_policies');
    }
};
