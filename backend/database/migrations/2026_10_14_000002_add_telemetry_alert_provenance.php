<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printer_alerts', static function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->change();
            $table->foreignId('telemetry_anchor_id')->nullable()->after('origin')
                ->constrained('printer_readings')->restrictOnDelete();
            $table->unique(
                ['tenant_id','printer_id','origin','code','telemetry_anchor_id'],
                'telemetry_incident_fingerprint'
            );
        });
        Schema::table('audit_entries', static function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->change();
        });
        // SQLite rebuilds the table for change(), and can recreate the old
        // partial unique index as a plain unconditional UNIQUE index.
        // Restore the intended active-only fingerprint on BOTH databases.
        DB::statement('DROP INDEX IF EXISTS alert_one_open_fingerprint');
        DB::statement("CREATE UNIQUE INDEX alert_one_open_fingerprint ON printer_alerts
            (tenant_id, printer_id, origin, code)
            WHERE status IN ('active','acknowledged','linked')");

    }

    public function down(): void
    {
        Schema::table('audit_entries', static function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable(false)->change();
        });
        Schema::table('printer_alerts', static function (Blueprint $table): void {
            $table->dropUnique('telemetry_incident_fingerprint');
            $table->dropConstrainedForeignId('telemetry_anchor_id');
            $table->foreignId('created_by_user_id')->nullable(false)->change();
        });
    }
};
