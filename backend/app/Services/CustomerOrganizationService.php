<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerDepartment;
use App\Models\CustomerLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerOrganizationService
{
    private const LOCATION_FIELDS = [
        'name','code','document','address_line','number','district',
        'city','state','postal_code','country','active',
    ];
    private const DEPARTMENT_FIELDS = ['name','code','responsible_name','responsible_email','active'];
    private const CENTER_FIELDS = ['code','name','active'];

    public function __construct(private readonly AuditWriter $audit) {}

    private function assertActiveCustomer(Customer $customer): void
    {
        if (!$customer->active) {
            throw ValidationException::withMessages(['customer' => 'Cliente inativo não permite novos vínculos.']);
        }
    }

    private function assertActiveLocation(CustomerLocation $location): void
    {
        if (!$location->active) {
            throw ValidationException::withMessages(['location' => 'Unidade inativa não permite novos vínculos.']);
        }
    }

    private function auditChange(
        Tenant $tenant, User $actor, string $entity, Model $record,
        array $fields, ?array $before,
    ): void {
        $after = $record->only($fields);
        if ($before === null || $before !== $after) {
            $action = $before === null ? 'created' : ($before['active'] && !$after['active'] ? 'deactivated' : 'updated');
            $this->audit->write($tenant, $actor, $entity.'.'.$action, $record, $before, $after);
        }
    }

    public function createLocation(Tenant $tenant, User $actor, int $customerId, array $data): CustomerLocation
    {
        return DB::transaction(function () use ($tenant, $actor, $customerId, $data): CustomerLocation {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            $this->assertActiveCustomer($customer);
            $location = $customer->locations()->create($data + ['tenant_id' => $tenant->id]);
            $this->auditChange($tenant, $actor, 'customer_location', $location, self::LOCATION_FIELDS, null);
            return $location;
        });
    }

    public function updateLocation(Tenant $tenant, User $actor, int $customerId, int $locationId, array $data): CustomerLocation
    {
        return DB::transaction(function () use ($tenant, $actor, $customerId, $locationId, $data): CustomerLocation {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            $location = $customer->locations()->lockForUpdate()->findOrFail($locationId);
            if (($data['active'] ?? $location->active) && !$customer->active) {
                $this->assertActiveCustomer($customer);
            }
            if (($data['active'] ?? $location->active) === false &&
                $location->departments()->where('active', true)->exists()) {
                throw ValidationException::withMessages([
                    'active' => 'Inative os departamentos desta unidade antes de inativá-la.',
                ]);
            }
            $before = $location->only(self::LOCATION_FIELDS);
            $location->update($data);
            $this->auditChange($tenant, $actor, 'customer_location', $location, self::LOCATION_FIELDS, $before);
            return $location->loadCount('departments');
        });
    }

    public function deactivateLocation(Tenant $tenant, User $actor, int $customerId, int $locationId): void
    {
        $this->updateLocation($tenant, $actor, $customerId, $locationId, ['active' => false]);
    }

    public function createDepartment(
        Tenant $tenant, User $actor, int $customerId, int $locationId, array $data,
    ): CustomerDepartment {
        return DB::transaction(function () use ($tenant, $actor, $customerId, $locationId, $data): CustomerDepartment {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            $location = $customer->locations()->lockForUpdate()->findOrFail($locationId);
            $this->assertActiveCustomer($customer);
            $this->assertActiveLocation($location);
            $department = $location->departments()->create($data + [
                'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            ]);
            $this->auditChange($tenant, $actor, 'customer_department', $department, self::DEPARTMENT_FIELDS, null);
            return $department;
        });
    }

    public function updateDepartment(
        Tenant $tenant, User $actor, int $customerId, int $locationId, int $departmentId, array $data,
    ): CustomerDepartment {
        return DB::transaction(function () use ($tenant, $actor, $customerId, $locationId, $departmentId, $data): CustomerDepartment {
            // Lock ancestors in the same order as creation and deactivation so
            // an active department cannot be re-enabled during unit shutdown.
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            $location = $customer->locations()->lockForUpdate()->findOrFail($locationId);
            $department = $location->departments()->lockForUpdate()->findOrFail($departmentId);
            if ($data['active'] ?? $department->active) {
                $this->assertActiveCustomer($customer);
                $this->assertActiveLocation($location);
            }
            $before = $department->only(self::DEPARTMENT_FIELDS);
            $department->update($data);
            $this->auditChange($tenant, $actor, 'customer_department', $department, self::DEPARTMENT_FIELDS, $before);
            return $department;
        });
    }

    public function deactivateDepartment(
        Tenant $tenant, User $actor, int $customerId, int $locationId, int $departmentId,
    ): void {
        $this->updateDepartment($tenant, $actor, $customerId, $locationId, $departmentId, ['active' => false]);
    }

    public function createCostCenter(Tenant $tenant, User $actor, int $customerId, array $data): CostCenter
    {
        return DB::transaction(function () use ($tenant, $actor, $customerId, $data): CostCenter {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            $this->assertActiveCustomer($customer);
            $center = $customer->costCenters()->create($data + ['tenant_id' => $tenant->id]);
            $this->auditChange($tenant, $actor, 'cost_center', $center, self::CENTER_FIELDS, null);
            return $center;
        });
    }

    public function updateCostCenter(
        Tenant $tenant, User $actor, int $customerId, int $centerId, array $data,
    ): CostCenter {
        return DB::transaction(function () use ($tenant, $actor, $customerId, $centerId, $data): CostCenter {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            $center = $customer->costCenters()->lockForUpdate()->findOrFail($centerId);
            if ($data['active'] ?? $center->active) {
                $this->assertActiveCustomer($customer);
            }
            $before = $center->only(self::CENTER_FIELDS);
            $center->update($data);
            $this->auditChange($tenant, $actor, 'cost_center', $center, self::CENTER_FIELDS, $before);
            return $center;
        });
    }

    public function deactivateCostCenter(Tenant $tenant, User $actor, int $customerId, int $centerId): void
    {
        $this->updateCostCenter($tenant, $actor, $customerId, $centerId, ['active' => false]);
    }
}
