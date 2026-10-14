<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('collector_health_incidents', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_agent_id')->constrained('collector_agents')->restrictOnDelete();
            $table->string('code', 48)->default('COLLECTOR_HEARTBEAT_LATE');
            $table->string('status', 24)->default('active');
            $table->timestamp('last_heartbeat_at');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 32)->nullable();
            $table->timestamps();
            $table->index(['tenant_id','customer_id','status','detected_at'], 'collector_incident_portfolio');
            $table->index(['collector_agent_id','detected_at'], 'collector_incident_history');
        });
        // A late agent has a single unresolved communication alert, regardless
        // of concurrent scheduled checks. Historical episodes remain separate.
        DB::statement("CREATE UNIQUE INDEX collector_one_active_communication_alert
            ON collector_health_incidents (collector_agent_id,code)
            WHERE status = 'active'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS collector_one_active_communication_alert');
        Schema::dropIfExists('collector_health_incidents');
    }
};
