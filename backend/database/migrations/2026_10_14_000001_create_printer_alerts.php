<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('printer_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id');
            $table->foreignId('printer_id');
            $table->foreignId('ticket_id')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('origin', 24)->default('operator');
            $table->string('code', 64);
            $table->string('category', 48);
            $table->string('severity', 16);
            $table->string('status', 24)->default('active');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id','id'], 'alert_tenant_key');
            $table->foreign(['tenant_id','customer_id','printer_id'], 'alert_printer_scope_fk')
                ->references(['tenant_id','customer_id','id'])->on('printers')->restrictOnDelete();
            $table->foreign(['tenant_id','ticket_id'], 'alert_ticket_scope_fk')
                ->references(['tenant_id','id'])->on('service_tickets')->restrictOnDelete();
            $table->index(['tenant_id','status','severity','created_at'], 'alert_backoffice_queue');
            $table->index(['tenant_id','customer_id','printer_id'], 'alert_printer_history');
        });

        // PostgreSQL 17 and SQLite support partial unique indexes.
        // Only one unresolved incident with the same code and origin per printer.
        DB::statement("CREATE UNIQUE INDEX alert_one_open_fingerprint ON printer_alerts
            (tenant_id, printer_id, origin, code)
            WHERE status IN ('active','acknowledged','linked')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS alert_one_open_fingerprint');
        Schema::dropIfExists('printer_alerts');
    }
};
