<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_user', static function (Blueprint $table): void {
            // Existing operators retain their current all-customers visibility.
            // Restricted scope must be explicitly configured by an owner/admin.
            $table->string('customer_scope', 12)->default('all');
        });

        Schema::create('customer_user_access', static function (Blueprint $table): void {
            $table->foreignId('tenant_id');
            $table->foreignId('user_id');
            $table->foreignId('customer_id');
            $table->timestamps();

            $table->primary(['tenant_id', 'user_id', 'customer_id'], 'customer_user_access_pk');
            $table->foreign(['tenant_id', 'user_id'], 'customer_access_membership_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'], 'customer_access_customer_fk')
                ->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user_access');
        Schema::table('tenant_user', static function (Blueprint $table): void {
            $table->dropColumn('customer_scope');
        });
    }
};
