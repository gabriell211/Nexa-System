<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('origin', 40);
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        Schema::table('printers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'active']);
            $table->dropColumn('active');
        });
    }
};
