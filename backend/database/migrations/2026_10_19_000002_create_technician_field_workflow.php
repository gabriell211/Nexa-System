<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_ticket_part_requests', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id');
            $table->foreignId('stock_item_id');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->unsignedInteger('quantity');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id','ticket_id'], 'field_parts_ticket_fk')
                ->references(['tenant_id','id'])->on('service_tickets')->restrictOnDelete();
            $table->foreign(['tenant_id','stock_item_id'], 'field_parts_stock_fk')
                ->references(['tenant_id','id'])->on('stock_items')->restrictOnDelete();
            $table->unique(['tenant_id','request_id'], 'field_parts_idempotency_unique');
            $table->index(['tenant_id','status','created_at'], 'field_parts_queue_idx');
        });

        Schema::create('service_ticket_signatures', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id');
            $table->foreignId('technician_user_id')->constrained('users')->restrictOnDelete();
            $table->string('signer_name', 180);
            $table->string('service_summary', 4000);
            $table->string('storage_path', 255)->unique();
            $table->char('sha256', 64);
            $table->timestamp('signed_at');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id','ticket_id'], 'field_signature_ticket_fk')
                ->references(['tenant_id','id'])->on('service_tickets')->restrictOnDelete();
            $table->unique(['tenant_id','ticket_id'], 'field_ticket_signature_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_signatures');
        Schema::dropIfExists('service_ticket_part_requests');
    }
};
