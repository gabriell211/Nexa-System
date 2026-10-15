<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['collector_incident_notifications','collector_notification_replays'] as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                // Set only from a positively acknowledged Resend API request.
                // Never match a webhook using an email address or subject.
                $table->uuid('provider_email_id')->nullable()->unique();
                $table->timestamp('provider_delivered_at')->nullable();
            });
        }
        Schema::create('collector_mail_webhook_events', static function (Blueprint $table): void {
            $table->id();
            // Hashing the Svix event ID is enough for redelivery suppression;
            // never persist the raw signed request or recipient details.
            $table->char('event_hash',64)->unique();
            $table->string('kind',32);
            $table->string('outcome',24);
            $table->string('target_type',16)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_mail_webhook_events');
        foreach (['collector_incident_notifications','collector_notification_replays'] as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                $table->dropUnique(['provider_email_id']);
                $table->dropColumn(['provider_email_id','provider_delivered_at']);
            });
        }
    }
};
