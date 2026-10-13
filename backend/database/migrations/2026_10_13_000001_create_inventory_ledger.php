<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_items', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('sku', 80);
            $table->string('name', 180);
            $table->string('category', 80);
            $table->string('unit', 16)->default('un');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku'], 'stock_item_tenant_sku_unique');
            $table->unique(['tenant_id', 'id'], 'stock_item_tenant_id_unique');
        });

        Schema::create('stock_locations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('kind', 16);
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('customer_location_id')->nullable();
            $table->foreignId('technician_user_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'stock_location_tenant_id_unique');
            $table->foreign(['tenant_id', 'customer_id'], 'stock_location_customer_scope_fk')
                ->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id', 'customer_location_id'], 'stock_location_unit_scope_fk')
                ->references(['tenant_id', 'customer_id', 'id'])->on('customer_locations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'technician_user_id'], 'stock_location_technician_scope_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
            $table->index(['tenant_id', 'kind', 'active']);
        });

        Schema::create('stock_balances', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'item_id', 'location_id'], 'stock_balance_position_unique');
            $table->foreign(['tenant_id', 'item_id'], 'stock_balance_item_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'location_id'], 'stock_balance_location_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
        });

        Schema::create('stock_movements', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('kind', 16);
            $table->foreignId('item_id');
            $table->unsignedBigInteger('source_location_id')->nullable();
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('source_before')->nullable();
            $table->unsignedBigInteger('source_after')->nullable();
            $table->unsignedBigInteger('destination_before')->nullable();
            $table->unsignedBigInteger('destination_after')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamps();
            $table->unique(['tenant_id', 'request_id'], 'stock_movement_idempotency_unique');
            $table->foreign(['tenant_id', 'item_id'], 'stock_movement_item_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'source_location_id'], 'stock_movement_source_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_location_id'], 'stock_movement_destination_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'item_id', 'created_at']);
            $table->index(['tenant_id', 'source_location_id', 'created_at']);
            $table->index(['tenant_id', 'destination_location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('stock_items');
    }
};
