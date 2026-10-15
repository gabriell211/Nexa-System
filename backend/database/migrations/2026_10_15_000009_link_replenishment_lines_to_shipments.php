<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_replenishment_plan_items', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'replenishment_line_scope_id');
        });
        Schema::table('stock_shipments', static function (Blueprint $table): void {
            $table->unsignedBigInteger('replenishment_plan_item_id')->nullable();
            $table->foreign(['tenant_id', 'replenishment_plan_item_id'], 'shipment_plan_line_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_replenishment_plan_items')->restrictOnDelete();
            $table->index(['tenant_id', 'replenishment_plan_item_id'], 'shipment_plan_line_lookup');
        });
    }
    public function down(): void
    {
        Schema::table('stock_shipments', static function (Blueprint $table): void {
            $table->dropForeign('shipment_plan_line_scope_fk');
            $table->dropIndex('shipment_plan_line_lookup');
            $table->dropColumn('replenishment_plan_item_id');
        });
        Schema::table('stock_replenishment_plan_items', static function (Blueprint $table): void {
            $table->dropUnique('replenishment_line_scope_id');
        });
    }
};
