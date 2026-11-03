<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Earlier releases scoped login names by tenant. Preserve existing
        // assignments and disambiguate collisions before enforcing global uniqueness.
        $members = DB::table('tenant_user')
            ->where('role', 'technician')
            ->whereNotNull('login_name')
            ->orderBy('tenant_id')
            ->orderBy('user_id')
            ->get(['tenant_id', 'user_id', 'login_name']);

        $reserved = [];
        foreach ($members as $member) {
            $login = (string) $member->login_name;
            if (isset($reserved[$login])) {
                $base = substr($login, 0, 48);
                $login = $base.'-'.$member->user_id;
                $suffix = 2;
                while (isset($reserved[$login])) {
                    $login = $base.'-'.$member->user_id.'-'.$suffix++;
                }
                DB::table('tenant_user')
                    ->where('tenant_id', $member->tenant_id)
                    ->where('user_id', $member->user_id)
                    ->update(['login_name' => $login]);
            }
            $reserved[$login] = true;
        }

        Schema::table('tenant_user', static function (Blueprint $table): void {
            $table->unique('login_name', 'tenant_user_login_name_global_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', static function (Blueprint $table): void {
            $table->dropUnique('tenant_user_login_name_global_unique');
        });
    }
};
