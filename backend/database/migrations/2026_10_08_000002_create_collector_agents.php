<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('collector_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('collector_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_agents');
    }
};
