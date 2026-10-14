<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
