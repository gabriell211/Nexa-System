<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('collector_mail_webhook_events',static function (Blueprint $table): void {
            // Verified, PII-free fields only. Unmatched events are retained for
            // reconciliation with the later-persisted Resend API message ID.
            $table->uuid('provider_email_id')->nullable();
            $table->string('bounce_category',16)->nullable();
            $table->index(['outcome','received_at','id'],'collector_pending_mail_events');
            $table->index(['provider_email_id','outcome'],'collector_pending_provider_events');
        });
    }

    public function down(): void
    {
        Schema::table('collector_mail_webhook_events',static function (Blueprint $table): void {
            $table->dropIndex('collector_pending_mail_events');
            $table->dropIndex('collector_pending_provider_events');
            $table->dropColumn(['provider_email_id','bounce_category']);
        });
    }
};