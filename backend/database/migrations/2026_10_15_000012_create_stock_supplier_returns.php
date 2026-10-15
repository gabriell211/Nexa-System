<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_purchase_receipt_matches', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'purchase_match_tenant_id_unique');
        });
        Schema::create('stock_supplier_returns', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->foreignId('purchase_receipt_match_id');
            $table->unsignedBigInteger('quantity');
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_user_id');
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('movement_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id','request_id'], 'supplier_return_request_unique');
            $table->index(['tenant_id','purchase_receipt_match_id','status'], 'supplier_return_match_state');
            $table->foreign(['tenant_id','purchase_receipt_match_id'], 'supplier_return_match_fk')
                ->references(['tenant_id','id'])->on('stock_purchase_receipt_matches')->restrictOnDelete();
            $table->foreign(['tenant_id','movement_id'], 'supplier_return_movement_fk')
                ->references(['tenant_id','id'])->on('stock_movements')->restrictOnDelete();
            $table->foreign(['tenant_id','created_by_user_id'], 'supplier_return_creator_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id','reviewed_by_user_id'], 'supplier_return_reviewer_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_supplier_returns');
        Schema::table('stock_purchase_receipt_matches', static function (Blueprint $table): void {
            $table->dropUnique('purchase_match_tenant_id_unique');
        });
    }
};
