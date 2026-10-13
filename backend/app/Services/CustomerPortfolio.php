<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CustomerPortfolio
{
    public function hasFullAccess(Request $request): bool
    {
        $role = $request->attributes->get('nexa_role');
        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }

        // A missing or invalid scope MUST NOT silently grant access.
        return $request->attributes->get('nexa_customer_scope') === 'all';
    }

    public function assignedIds(Request $request): QueryBuilder
    {
        $tenant = $request->attributes->get('nexa_tenant');

        return DB::table('customer_user_access')
            ->select('customer_id')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id);
    }

    public function customers(Builder $query, Request $request): Builder
    {
        if (!$this->hasFullAccess($request)) {
            $query->whereIn('customers.id', $this->assignedIds($request));
        }

        return $query;
    }

    public function printers(Builder $query, Request $request): Builder
    {
        if (!$this->hasFullAccess($request)) {
            $query->whereIn('printers.customer_id', $this->assignedIds($request));
        }

        return $query;
    }

    public function assertCustomer(Request $request, int $customerId): void
    {
        if ($this->hasFullAccess($request)) {
            return;
        }
        $tenant = $request->attributes->get('nexa_tenant');
        $allowed = DB::table('customer_user_access')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->where('customer_id', $customerId)
            ->exists();
        if (!$allowed) {
            abort(404);
        }
    }

    public function assertFull(Request $request): void
    {
        if (!$this->hasFullAccess($request)) {
            abort(403, 'Esta operação exige acesso a toda a carteira.');
        }
    }
}
