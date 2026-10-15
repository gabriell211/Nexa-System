<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_replenishment_plan_items', static function (Blueprint $table): void {
            $table->unique(['tenant_id','plan_id','id'], 'replenishment_line_plan_scope_unique');
        });

        Schema::create('stock_purchase_orders', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->foreignId('plan_id');
            $table->foreignId('supplier_id');
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id','request_id'], 'purchase_order_request_scope_unique');
            $table->unique(['tenant_id','id'], 'purchase_order_scope_unique');
            $table->unique(['tenant_id','plan_id','id'], 'purchase_order_plan_scope_unique');
            $table->index(['tenant_id','status','id'], 'purchase_order_status_index');
            $table->foreign(['tenant_id','plan_id'], 'purchase_order_plan_fk')
                ->references(['tenant_id','id'])->on('stock_replenishment_plans')->restrictOnDelete();
            $table->foreign(['tenant_id','supplier_id'], 'purchase_order_supplier_fk')
                ->references(['tenant_id','id'])->on('stock_suppliers')->restrictOnDelete();
            $table->foreign(['tenant_id','created_by_user_id'], 'purchase_order_creator_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id','approved_by_user_id'], 'purchase_order_approver_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id','cancelled_by_user_id'], 'purchase_order_canceller_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
        });

        Schema::create('stock_purchase_order_lines', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('plan_id');
            $table->foreignId('purchase_order_id');
            $table->foreignId('plan_line_id');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('unit_cost_cents');
            $table->timestamps();
            $table->unique(['purchase_order_id','plan_line_id'], 'purchase_order_line_unique');
            $table->index(['tenant_id','plan_line_id'], 'purchase_order_line_quota_index');
            $table->foreign(['tenant_id','plan_id','purchase_order_id'], 'purchase_line_order_fk')
                ->references(['tenant_id','plan_id','id'])->on('stock_purchase_orders')->restrictOnDelete();
            $table->foreign(['tenant_id','plan_id','plan_line_id'], 'purchase_line_plan_item_fk')
                ->references(['tenant_id','plan_id','id'])->on('stock_replenishment_plan_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchase_order_lines');
        Schema::dropIfExists('stock_purchase_orders');
        Schema::table('stock_replenishment_plan_items', static function (Blueprint $table): void {
            $table->dropUnique('replenishment_line_plan_scope_unique');
        });
    }
};
