<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_supplier_replacement_receipts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('replacement_case_id');
            $table->foreignId('purchase_receipt_match_id');
            $table->unsignedBigInteger('quantity');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('note', 500);
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id'], 'replacement_receipt_request_unique');
            $table->unique(['tenant_id', 'replacement_case_id', 'purchase_receipt_match_id'], 'replacement_receipt_pair_unique');
            $table->index(['tenant_id', 'purchase_receipt_match_id'], 'replacement_receipt_match_index');
            $table->foreign(['tenant_id', 'replacement_case_id'], 'replacement_receipt_case_fk')
                ->references(['tenant_id', 'id'])->on('stock_supplier_replacement_cases')->restrictOnDelete();
            $table->foreign(['tenant_id', 'purchase_receipt_match_id'], 'replacement_receipt_match_fk')
                ->references(['tenant_id', 'id'])->on('stock_purchase_receipt_matches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_user_id'], 'replacement_receipt_actor_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_supplier_replacement_receipts');
    }
};
