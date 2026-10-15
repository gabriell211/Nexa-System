<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('collector_incident_notifications', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_health_incident_id')
                ->constrained('collector_health_incidents')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('stage',16); // initial | escalation
            $table->string('status',24); // attempting | accepted | failed | skipped
            $table->timestamp('attempted_at');
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_category',48)->nullable();
            $table->timestamps();
            $table->unique(
                ['collector_health_incident_id','recipient_user_id','stage'],
                'collector_notice_once_per_recipient_stage'
            );
            $table->index(['tenant_id','status','attempted_at'],'collector_notice_delivery_audit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_incident_notifications');
    }
};
