<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();
            $table->primary(['tenant_id', 'user_id']);
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('document', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });
        Schema::create('printers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('manufacturer', 100);
            $table->string('model', 160);
            $table->string('serial_number', 160)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id', 'status']);
        });
        Schema::create('printer_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('printer_id')->constrained()->restrictOnDelete();
            $table->uuid('sample_id');
            $table->unsignedBigInteger('meter_total')->nullable();
            $table->unsignedBigInteger('meter_mono')->nullable();
            $table->unsignedBigInteger('meter_color')->nullable();
            $table->timestamp('collected_at');
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['tenant_id', 'sample_id']);
            $table->index(['tenant_id', 'printer_id', 'collected_at']);
        });
    }
    public function down(): void
    {
        foreach (['printer_readings', 'printers', 'customers', 'personal_access_tokens', 'tenant_user', 'password_reset_tokens', 'users', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
