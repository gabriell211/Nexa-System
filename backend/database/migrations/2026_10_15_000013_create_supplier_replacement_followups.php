<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_supplier_returns', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'supplier_return_tenant_id_unique');
        });

        Schema::create('stock_supplier_replacement_cases', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('supplier_return_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->foreignId('assigned_user_id');
            $table->foreignId('created_by_user_id');
            $table->date('due_on');
            $table->string('status', 24)->default('awaiting_supplier');
            $table->unsignedInteger('version')->default(1);
            $table->string('supplier_reference', 120)->nullable();
            $table->timestamp('supplier_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'replacement_case_tenant_id_unique');
            $table->unique(['tenant_id', 'request_id'], 'replacement_case_request_unique');
            $table->unique(['tenant_id', 'supplier_return_id'], 'replacement_case_return_unique');
            $table->index(['tenant_id', 'status', 'due_on'], 'replacement_case_due_index');
            $table->foreign(['tenant_id', 'supplier_return_id'], 'replacement_case_return_fk')
                ->references(['tenant_id', 'id'])->on('stock_supplier_returns')->restrictOnDelete();
            $table->foreign(['tenant_id', 'assigned_user_id'], 'replacement_case_assignee_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_user_id'], 'replacement_case_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
        });

        Schema::create('stock_supplier_replacement_events', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('replacement_case_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('action', 24);
            $table->string('note', 500);
            $table->string('supplier_reference', 120)->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('actor_user_id');
            $table->timestamps();

            $table->unique(['tenant_id','request_id'], 'replacement_event_request_unique');
            $table->index(['tenant_id','replacement_case_id','id'], 'replacement_event_history_index');
            $table->foreign(['tenant_id', 'replacement_case_id'], 'replacement_event_case_fk')
                ->references(['tenant_id','id'])->on('stock_supplier_replacement_cases')->restrictOnDelete();
            $table->foreign(['tenant_id','actor_user_id'], 'replacement_event_actor_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_supplier_replacement_events');
        Schema::dropIfExists('stock_supplier_replacement_cases');
        Schema::table('stock_supplier_returns', static function (Blueprint $table): void {
            $table->dropUnique('supplier_return_tenant_id_unique');
        });
    }
};
