<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    private const FIELDS = ['name', 'document', 'email', 'active'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function create(Tenant $tenant, User $actor, array $data): Customer
    {
        return DB::transaction(function () use ($tenant, $actor, $data): Customer {
            $customer = $tenant->customers()->create($data);
            $this->audit->write($tenant, $actor, 'customer.created', $customer, null, $customer->only(self::FIELDS));

            return $customer;
        });
    }

    public function update(Tenant $tenant, User $actor, int $id, array $data): Customer
    {
        return DB::transaction(function () use ($tenant, $actor, $id, $data): Customer {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($id);
            $before = $customer->only(self::FIELDS);
            $customer->update($data);
            $customer->refresh();

            if ($before !== $customer->only(self::FIELDS)) {
                $this->audit->write($tenant, $actor, 'customer.updated', $customer, $before, $customer->only(self::FIELDS));
            }

            return $customer;
        });
    }

    public function deactivate(Tenant $tenant, User $actor, int $id): void
    {
        DB::transaction(function () use ($tenant, $actor, $id): void {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($id);
            if (!$customer->active) {
                return;
            }

            $before = $customer->only(self::FIELDS);
            $customer->update(['active' => false]);
            $this->audit->write($tenant, $actor, 'customer.deactivated', $customer, $before, $customer->only(self::FIELDS));
        });
    }
}
