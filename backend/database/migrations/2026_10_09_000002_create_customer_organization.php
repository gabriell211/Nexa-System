<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'customers_tenant_id_unique');
        });

        Schema::create('customer_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('customer_id');
            $table->string('name', 180);
            $table->string('code', 64)->nullable();
            $table->string('document', 32)->nullable();
            $table->string('address_line', 240)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->string('country', 2)->default('BR');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign(['tenant_id', 'customer_id'], 'location_customer_scope_fk')
                ->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->unique(['tenant_id', 'customer_id', 'id'], 'location_scope_unique');
            $table->unique(['tenant_id', 'customer_id', 'code'], 'location_code_unique');
            $table->index(['tenant_id', 'customer_id', 'active']);
        });

        Schema::create('customer_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('customer_id');
            $table->foreignId('location_id');
            $table->string('name', 180);
            $table->string('code', 64)->nullable();
            $table->string('responsible_name', 180)->nullable();
            $table->string('responsible_email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign(['tenant_id', 'customer_id', 'location_id'], 'department_location_scope_fk')
                ->references(['tenant_id', 'customer_id', 'id'])->on('customer_locations')->restrictOnDelete();
            $table->unique(['location_id', 'code'], 'department_code_unique');
            $table->index(['tenant_id', 'customer_id', 'location_id', 'active'], 'department_listing_idx');
        });

        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('customer_id');
            $table->string('code', 64);
            $table->string('name', 180);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign(['tenant_id', 'customer_id'], 'cost_center_customer_scope_fk')
                ->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->unique(['tenant_id', 'customer_id', 'code'], 'cost_center_code_unique');
            $table->index(['tenant_id', 'customer_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('customer_departments');
        Schema::dropIfExists('customer_locations');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_tenant_id_unique');
        });
    }
};
