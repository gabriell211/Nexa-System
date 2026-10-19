<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StaffPortfolioService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function update(Tenant $tenant, User $actor, int $targetId, string $scope, array $ids): array
    {
        return DB::transaction(function () use ($tenant, $actor, $targetId, $scope, $ids): array {
            $member = DB::table('tenant_user')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $targetId)
                ->lockForUpdate()
                ->first();

            if (!$member) abort(404);
            if ($member->role === 'customer' && $scope === 'all') {
                abort(422, 'Contas de clientes exigem carteiras atribuídas explicitamente.');
            }
            if (in_array($member->role, ['owner', 'admin'], true)) {
                abort(403, 'Escopo dos administradores não é restringível.');
            }

            $target = User::query()->findOrFail($targetId);
            $beforeIds = DB::table('customer_user_access')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $targetId)
                ->orderBy('customer_id')->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
            $before = ['scope' => $member->customer_scope, 'customer_ids' => $beforeIds];
            $ids = $scope === 'assigned' ? array_values(array_unique(array_map('intval', $ids))) : [];
            sort($ids);
            // Validate again within the transaction. Foreign keys protect against
            // concurrent cross-tenant insertions even if a customer disappears.
            $count = DB::table('customers')
                ->where('tenant_id', $tenant->id)->whereIn('id', $ids)->count();
            if ($count !== count($ids)) abort(422, 'Há clientes inválidos para esta empresa.');

            DB::table('customer_user_access')->where('tenant_id', $tenant->id)
                ->where('user_id', $targetId)->delete();
            if ($ids !== []) {
                DB::table('customer_user_access')->insert(array_map(
                    static fn (int $id): array => [
                        'tenant_id' => $tenant->id, 'user_id' => $targetId,
                        'customer_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                    ],
                    $ids
                ));
            }
            DB::table('tenant_user')->where('tenant_id', $tenant->id)
                ->where('user_id', $targetId)
                ->update(['customer_scope' => $scope, 'updated_at' => now()]);
            $after = ['scope' => $scope, 'customer_ids' => $ids];

            if ($before !== $after) {
                $this->audit->write($tenant, $actor, 'staff.portfolio.updated', $target, $before, $after);
            }
            return $after;
        });
    }
}
