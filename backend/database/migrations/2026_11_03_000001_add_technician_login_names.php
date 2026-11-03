<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_user', static function (Blueprint $table): void {
            $table->string('login_name', 64)->nullable();
            $table->unique(['tenant_id', 'login_name'], 'tenant_user_login_name_unique');
        });

        // Keep existing technicians usable, even when their display names repeat.
        $members = DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.role', 'technician')
            ->orderBy('tenant_user.tenant_id')
            ->orderBy('tenant_user.user_id')
            ->get(['tenant_user.tenant_id', 'tenant_user.user_id', 'users.name']);

        $tenantId = null;
        $reserved = [];
        foreach ($members as $member) {
            $currentTenant = (int) $member->tenant_id;
            if ($tenantId !== $currentTenant) {
                $tenantId = $currentTenant;
                $reserved = [];
            }
            $slug = Str::slug((string) $member->name);
            $base = preg_match('/^[a-z]/', $slug) === 1 ? $slug : 'tecnico';
            $base = substr($base, 0, 52);
            $login = $base;
            $suffix = 2;
            while (isset($reserved[$login])) {
                $login = $base.'-'.$suffix++;
            }
            $reserved[$login] = true;
            DB::table('tenant_user')
                ->where('tenant_id', $currentTenant)
                ->where('user_id', $member->user_id)
                ->update(['login_name' => $login]);
        }
    }

    public function down(): void
    {
        Schema::table('tenant_user', static function (Blueprint $table): void {
            $table->dropUnique('tenant_user_login_name_unique');
            $table->dropColumn('login_name');
        });
    }
};
