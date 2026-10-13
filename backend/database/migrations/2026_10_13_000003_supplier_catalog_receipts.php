<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_items', static function (Blueprint $table): void {
            $table->string('brand', 120)->nullable();
            $table->string('color', 40)->nullable();
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->unsignedInteger('yield_pages')->nullable();
        });

        Schema::create('stock_suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name', 180);
            $table->string('email', 180)->nullable();
            $table->string('document', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id','code'], 'stock_supplier_code_per_tenant');
            $table->unique(['tenant_id','id'], 'stock_supplier_scope_id');
        });

        Schema::create('stock_item_compatibilities', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('item_id');
            $table->string('printer_manufacturer', 120);
            $table->string('printer_model', 160);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['tenant_id', 'item_id'], 'stock_compat_item_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->unique(['tenant_id','item_id','printer_manufacturer','printer_model'],
                'stock_compat_model_unique');
        });

        Schema::table('stock_movements', static function (Blueprint $table): void {
            $table->unique(['tenant_id','id'], 'stock_movement_scope_id');
        });

        // Each documented receipt represents one ledger receipt; lots are recorded
        // at inbound level only until lot-level consumption is implemented.
        Schema::create('stock_receipt_details', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('movement_id');
            $table->foreignId('supplier_id');
            $table->string('invoice_reference', 100);
            $table->string('lot_number', 100);
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('unit_cost_cents');
            $table->timestamps();
            $table->unique(['tenant_id', 'movement_id'], 'stock_receipt_unique_movement');
            $table->foreign(['tenant_id','movement_id'], 'stock_receipt_movement_scope_fk')
                ->references(['tenant_id','id'])->on('stock_movements')->restrictOnDelete();
            $table->foreign(['tenant_id','supplier_id'], 'stock_receipt_supplier_scope_fk')
                ->references(['tenant_id','id'])->on('stock_suppliers')->restrictOnDelete();
            $table->index(['tenant_id','lot_number']);
            $table->index(['tenant_id','expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_details');
        Schema::table('stock_movements', static function (Blueprint $table): void {
            $table->dropUnique('stock_movement_scope_id');
        });
        Schema::dropIfExists('stock_item_compatibilities');
        Schema::dropIfExists('stock_suppliers');
        Schema::table('stock_items', static function (Blueprint $table): void {
            $table->dropColumn(['brand','color','price_cents','cost_cents','yield_pages']);
        });
    }
};
