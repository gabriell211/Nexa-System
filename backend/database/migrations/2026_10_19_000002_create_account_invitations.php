<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_invitations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $table->char('token_sha256', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id','user_id'], 'invitation_single_pending_per_account');
        });
        Schema::create('customer_account_settings', static function (Blueprint $table): void {
            $table->foreignId('tenant_id');
            $table->foreignId('user_id');
            $table->boolean('view_reports')->default(true);
            $table->boolean('view_other_tickets')->default(false);
            $table->timestamps();
            $table->primary(['tenant_id','user_id']);
            $table->foreign(['tenant_id','user_id'], 'customer_account_membership_fk')
                ->references(['tenant_id','user_id'])->on('tenant_user')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('customer_account_settings');
        Schema::dropIfExists('account_invitations');
    }
};
