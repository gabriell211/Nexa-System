<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('collector_agents', static function (Blueprint $table): void {
            $table->timestamp('heartbeat_at')->nullable();
            $table->index(['tenant_id','active','heartbeat_at'], 'collector_heartbeat_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('collector_agents', static function (Blueprint $table): void {
            $table->dropIndex('collector_heartbeat_lookup');
            $table->dropColumn('heartbeat_at');
        });
    }
};
