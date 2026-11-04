<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('collector_printer_observations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_agent_id')->constrained('collector_agents')->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('serial_number', 160)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->boolean('monitored');
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['collector_agent_id', 'printer_id'], 'collector_printer_observation_unique');
            $table->index(['tenant_id', 'customer_id', 'observed_at'], 'collector_printer_observation_portfolio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_printer_observations');
    }
};
