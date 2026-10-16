<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_contracts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('customer_id');
            $table->string('document_number', 80);
            $table->string('title', 180);
            $table->string('provider_unit_name', 180);
            $table->string('billing_address', 500);
            $table->string('billing_timezone', 64)->default('America/Sao_Paulo');
            $table->date('effective_on');
            $table->date('billing_starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'customer_contract_scope_id');
            $table->unique(['tenant_id', 'document_number'], 'customer_contract_document_unique');
            $table->index(['tenant_id', 'customer_id', 'active'], 'customer_contract_customer_index');
            $table->foreign(['tenant_id', 'customer_id'], 'customer_contract_customer_fk')
                ->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_user_id'], 'customer_contract_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete();
        });

        // Both supported databases enforce this under concurrent requests.
        DB::statement('CREATE UNIQUE INDEX customer_contract_one_active_default ON customer_contracts (tenant_id, customer_id) WHERE is_default = true AND active = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contracts');
    }
};
