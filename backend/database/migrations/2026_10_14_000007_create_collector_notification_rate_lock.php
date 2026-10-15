<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The singleton lock is intentionally persistent across all workers
        // and tenants. A PostgreSQL SELECT FOR UPDATE serializes *all* new
        // mail reservations; SQLite serializes writes in the same transaction.
        Schema::create('collector_notification_rate_locks',static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('collector_notification_rate_locks')->insert(['id'=>1]);

        // No message content, recipient address or transport credentials are
        // stored in this rate-budget lock. Existing notification attempts are
        // counted using their immutable attempted_at timestamp.
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_notification_rate_locks');
    }
};
