<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_tickets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('printer_id')->nullable();
            $table->foreignId('assigned_user_id')->nullable();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 180);
            $table->text('description');
            $table->string('category', 60);
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('triage');
            $table->string('origin', 24)->default('backoffice');
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id','id'], 'ticket_scope_id');
            $table->foreign(['tenant_id','customer_id'], 'ticket_customer_scope_fk')
                ->references(['tenant_id','id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id','assigned_user_id'], 'ticket_assignee_scope_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->restrictOnDelete();
            $table->index(['tenant_id','status','priority','created_at'], 'ticket_queue_index');
            $table->index(['tenant_id','customer_id','created_at'], 'ticket_customer_index');
        });

        Schema::create('service_ticket_comments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('ticket_id');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('internal')->default(true);
            $table->text('body');
            $table->timestamps();
            $table->foreign(['tenant_id','ticket_id'], 'ticket_comment_scope_fk')
                ->references(['tenant_id','id'])->on('service_tickets')->restrictOnDelete();
            $table->index(['tenant_id','ticket_id','created_at'], 'ticket_comment_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_comments');
        Schema::dropIfExists('service_tickets');
    }
};
