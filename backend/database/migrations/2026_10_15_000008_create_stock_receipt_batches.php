<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Composite keys prevent a batch line referencing a receipt from a different tenant.
        Schema::table('stock_receipt_details', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'stock_receipt_tenant_id_unique');
        });

        Schema::create('stock_receipt_batches', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_id');
            $table->string('invoice_reference', 100);
            $table->unsignedSmallInteger('line_count');
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id'], 'stock_receipt_batch_request_unique');
            $table->unique(['tenant_id', 'id'], 'stock_receipt_batch_scope_id');
            $table->foreign(['tenant_id', 'supplier_id'], 'stock_receipt_batch_supplier_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_suppliers')->restrictOnDelete();
        });

        Schema::create('stock_receipt_batch_lines', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('batch_id');
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('receipt_detail_id');
            $table->unique(['batch_id', 'line_number'], 'stock_receipt_batch_line_position_unique');
            $table->unique('receipt_detail_id', 'stock_receipt_batch_receipt_unique');
            $table->foreign(['tenant_id', 'batch_id'], 'stock_receipt_batch_lines_batch_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_receipt_batches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'receipt_detail_id'], 'stock_receipt_batch_lines_receipt_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_receipt_details')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_batch_lines');
        Schema::dropIfExists('stock_receipt_batches');
        Schema::table('stock_receipt_details', static function (Blueprint $table): void {
            $table->dropUnique('stock_receipt_tenant_id_unique');
        });
    }
};
