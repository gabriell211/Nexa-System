<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_replenishment_plans', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->date('due_on');
            $table->foreignId('responsible_user_id');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'replenishment_plan_tenant_id_unique');
            $table->unique(['tenant_id', 'request_id'], 'replenishment_plan_request_unique');
            $table->foreign(['tenant_id', 'responsible_user_id'], 'replenishment_plan_responsible_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_user_id'], 'replenishment_plan_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id', 'approved_by_user_id'], 'replenishment_plan_approver_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cancelled_by_user_id'], 'replenishment_plan_canceller_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'due_on'], 'replenishment_plan_status_due_index');
            $table->index(['tenant_id', 'responsible_user_id', 'due_on'], 'replenishment_plan_owner_due_index');
        });

        Schema::create('stock_replenishment_plan_items', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('plan_id');
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            // Historical policy id is retained when the live policy is removed.
            // It is verified under tenant scope before each approval.
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->string('origin', 16);
            $table->unsignedBigInteger('available_quantity')->nullable();
            $table->unsignedBigInteger('suggested_quantity')->nullable();
            $table->unsignedBigInteger('critical_quantity')->nullable();
            $table->unsignedBigInteger('safety_quantity')->nullable();
            $table->unsignedBigInteger('minimum_quantity')->nullable();
            $table->unsignedBigInteger('target_quantity')->nullable();
            $table->unsignedBigInteger('quantity');
            $table->boolean('safety_item')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'plan_id', 'item_id', 'location_id'], 'replenishment_plan_position_unique');
            $table->foreign(['tenant_id', 'plan_id'], 'replenishment_plan_item_plan_fk')
                ->references(['tenant_id', 'id'])->on('stock_replenishment_plans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'item_id'], 'replenishment_plan_item_item_fk')
                ->references(['tenant_id', 'id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'location_id'], 'replenishment_plan_item_location_fk')
                ->references(['tenant_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'policy_id'], 'replenishment_plan_policy_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_replenishment_plan_items');
        Schema::dropIfExists('stock_replenishment_plans');
    }
};
