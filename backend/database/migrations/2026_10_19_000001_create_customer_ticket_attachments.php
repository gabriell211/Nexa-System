<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_ticket_printers', static function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id');
            $table->foreignId('printer_id')->constrained('printers')->restrictOnDelete();
            $table->primary(['ticket_id', 'printer_id'], 'ticket_printers_unique');
            $table->foreign(['tenant_id', 'ticket_id'], 'ticket_printers_tenant_scope_fk')
                ->references(['tenant_id', 'id'])->on('service_tickets')->restrictOnDelete();
            $table->index(['tenant_id', 'printer_id'], 'ticket_printers_tenant_lookup');
        });

        Schema::create('service_ticket_attachments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_name', 200);
            $table->string('storage_path', 255)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('bytes');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['tenant_id', 'ticket_id'], 'ticket_attachment_tenant_scope_fk')
                ->references(['tenant_id', 'id'])->on('service_tickets')->restrictOnDelete();
            $table->index(['tenant_id', 'ticket_id', 'id'], 'ticket_attachment_lookup');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('service_ticket_attachments');
        Schema::dropIfExists('service_ticket_printers');
    }
};
