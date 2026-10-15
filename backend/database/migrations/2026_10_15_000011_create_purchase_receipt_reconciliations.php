<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_purchase_order_lines', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'purchase_order_id', 'id'], 'purchase_order_line_scope_fk_key');
        });

        Schema::create('stock_purchase_receipt_matches', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('purchase_order_id');
            $table->foreignId('purchase_order_line_id');
            $table->foreignId('receipt_detail_id');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('received_unit_cost_cents');
            $table->string('variance_reason', 500)->nullable();
            $table->foreignId('created_by_user_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'receipt_detail_id'], 'purchase_receipt_one_owner');
            $table->index(['tenant_id', 'purchase_order_line_id'], 'purchase_receipt_line_search');
            $table->foreign(['tenant_id', 'purchase_order_id', 'purchase_order_line_id'], 'purchase_receipt_line_scope_fk')
                ->references(['tenant_id', 'purchase_order_id', 'id'])
                ->on('stock_purchase_order_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'receipt_detail_id'], 'purchase_receipt_detail_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_receipt_details')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_user_id'], 'purchase_receipt_operator_scope_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchase_receipt_matches');
        Schema::table('stock_purchase_order_lines', static function (Blueprint $table): void {
            $table->dropUnique('purchase_order_line_scope_fk_key');
        });
    }
};
