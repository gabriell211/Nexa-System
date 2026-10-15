<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('collector_notification_replays', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_health_incident_id')
                ->constrained('collector_health_incidents')->restrictOnDelete();
            $table->foreignId('original_notification_id')
                ->constrained('collector_incident_notifications')->restrictOnDelete();
            $table->string('review_reference',64);
            $table->string('status',24); // attempting | accepted | failed | skipped
            $table->timestamp('attempted_at');
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_category',48)->nullable();
            $table->timestamps();
            // At most ONE human-authorized replay for one original transport
            // failure. This is immutable even if the retry itself fails.
            $table->unique('original_notification_id','collector_one_reviewed_replay');
            $table->index(['tenant_id','attempted_at'],'collector_replay_budget');
            $table->index(['collector_health_incident_id','status'],'collector_replay_incident');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_notification_replays');
    }
};