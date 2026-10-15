<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_counts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('request_id');
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            $table->unsignedBigInteger('expected_quantity');
            $table->unsignedBigInteger('counted_quantity');
            $table->foreignId('observed_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->foreignId('counted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('counted_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 400)->nullable();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id'], 'stock_count_request_unique');
            $table->foreign(['tenant_id', 'item_id'], 'stock_count_item_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'location_id'], 'stock_count_location_scope_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'id'], 'stock_count_status_index');
            $table->index(['tenant_id', 'item_id', 'location_id', 'id'], 'stock_count_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
