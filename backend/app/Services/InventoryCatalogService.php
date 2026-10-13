<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryCatalogService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function createItem(Tenant $tenant, User $actor, array $data): StockItem
    {
        return DB::transaction(function () use ($tenant, $actor, $data): StockItem {
            $item = StockItem::query()->create($data + ['tenant_id' => $tenant->id]);
            $this->audit->write($tenant, $actor, 'stock.item.created', $item, null,
                $item->only(['sku','name','category','unit','active','brand','color','price_cents','cost_cents','yield_pages']));
            return $item;
        });
    }

    public function createLocation(Tenant $tenant, User $actor, array $data): StockLocation
    {
        return DB::transaction(function () use ($tenant, $actor, $data): StockLocation {
            $kind = $data['kind'];
            $customerId = $data['customer_id'] ?? null;
            $unitId = $data['customer_location_id'] ?? null;
            $technicianId = $data['technician_user_id'] ?? null;

            if ($kind === 'provider' && ($customerId !== null || $unitId !== null || $technicianId !== null)) {
                throw ValidationException::withMessages(['kind' => 'Depósito do provedor não recebe vínculos externos.']);
            }
            if ($kind === 'technician') {
                if ($technicianId === null || $customerId !== null || $unitId !== null ||
                    !$tenant->users()->whereKey($technicianId)->where('users.active', true)->exists()) {
                    throw ValidationException::withMessages(['technician_user_id' => 'Técnico precisa ser membro ativo da empresa.']);
                }
            }
            if ($kind === 'customer') {
                if ($customerId === null || $unitId === null || $technicianId !== null ||
                    !$tenant->customers()->whereKey($customerId)->where('active', true)->exists() ||
                    !$tenant->customers()->findOrFail($customerId)->locations()
                        ->whereKey($unitId)->where('active', true)->exists()) {
                    throw ValidationException::withMessages(['customer_location_id' => 'Unidade ativa deve pertencer ao cliente informado.']);
                }
            }

            $location = StockLocation::query()->create($data + ['tenant_id' => $tenant->id]);
            $this->audit->write($tenant, $actor, 'stock.location.created', $location, null,
                $location->only(['name','kind','customer_id','customer_location_id','technician_user_id','active']));
            return $location;
        });
    }
}
