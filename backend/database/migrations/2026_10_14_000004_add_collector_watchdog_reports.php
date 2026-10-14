<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('collector_agents', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('watchdog_recent_incidents')->nullable();
            $table->boolean('watchdog_circuit_open_reported')->nullable();
            $table->timestamp('watchdog_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collector_agents', static function (Blueprint $table): void {
            $table->dropColumn([
                'watchdog_recent_incidents',
                'watchdog_circuit_open_reported',
                'watchdog_reported_at',
            ]);
        });
    }
};
