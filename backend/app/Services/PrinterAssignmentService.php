<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CostCenter;
use App\Models\CustomerDepartment;
use App\Models\CustomerLocation;
use App\Models\Printer;
use App\Models\PrinterAssignment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrinterAssignmentService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function assign(Tenant $tenant, User $actor, int $printerId, array $input): PrinterAssignment
    {
        return DB::transaction(function () use ($tenant, $actor, $printerId, $input): PrinterAssignment {
            // Lock in the same order as organizational changes: customer, location,
            // then printer. This closes the race between a relocation and location
            // deactivation, and serializes concurrent relocations of one device.
            $candidate = $tenant->printers()->findOrFail($printerId);
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($candidate->customer_id);
            if (!$customer->active) {
                throw ValidationException::withMessages(['printer' => 'Cliente inativo.']);
            }
            $scope = ['tenant_id' => $tenant->id, 'customer_id' => $customer->id];
            $location = CustomerLocation::query()->where($scope)
                ->whereKey($input['location_id'])->lockForUpdate()->first();
            if ($location === null || !$location->active) {
                throw ValidationException::withMessages(['location_id' => 'Unidade inválida para este cliente.']);
            }
            $printer = $tenant->printers()->lockForUpdate()->findOrFail($printerId);
            if (!$printer->active || $printer->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['printer' => 'Impressora ou cliente inválido.']);
            }

            $departmentId = $input['department_id'] ?? null;
            $centerId = $input['cost_center_id'] ?? null;
            if ($departmentId !== null && !CustomerDepartment::query()->where($scope)
                ->where('location_id', $location->id)->whereKey($departmentId)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['department_id' => 'Departamento não pertence à unidade ativa.']);
            }
            if ($centerId !== null && !CostCenter::query()->where($scope)
                ->whereKey($centerId)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['cost_center_id' => 'Centro de custo não pertence ao cliente ativo.']);
            }

            $current = $printer->assignments()->whereNull('released_at')->lockForUpdate()->first();
            if ($current && (int) $current->location_id === (int) $location->id
                && $current->department_id === $departmentId && $current->cost_center_id === $centerId) {
                throw ValidationException::withMessages(['location_id' => 'A impressora já está nesta posição.']);
            }

            $previous = $current ? $current->only(['location_id', 'department_id', 'cost_center_id', 'assigned_at']) : null;
            $now = now('UTC');
            if ($current) {
                $current->update(['released_at' => $now]);
            }

            $new = $printer->assignments()->create($scope + [
                'location_id' => $location->id, 'department_id' => $departmentId,
                'cost_center_id' => $centerId, 'actor_user_id' => $actor->id,
                'reason' => $input['reason'], 'assigned_at' => $now,
            ]);
            $this->audit->write($tenant, $actor, 'printer.assignment.changed', $printer, $previous, [
                'assignment_id' => $new->id, 'location_id' => $new->location_id,
                'department_id' => $new->department_id, 'cost_center_id' => $new->cost_center_id,
                'assigned_at' => $new->assigned_at, 'reason' => $new->reason,
            ]);

            return $new->load(['location:id,name','department:id,name','costCenter:id,name,code','actor:id,name']);
        });
    }

    public function release(Tenant $tenant, User $actor, int $printerId, string $reason): void
    {
        DB::transaction(function () use ($tenant, $actor, $printerId, $reason): void {
            $printer = $tenant->printers()->lockForUpdate()->findOrFail($printerId);
            $current = $printer->assignments()->whereNull('released_at')->lockForUpdate()->first();
            if ($current === null) {
                throw ValidationException::withMessages(['printer' => 'A impressora não possui alocação ativa.']);
            }
            $before = $current->only(['location_id', 'department_id', 'cost_center_id','assigned_at']);
            $current->update(['released_at' => now('UTC')]);
            $this->audit->write($tenant, $actor, 'printer.assignment.released', $printer, $before, [
                'location_id' => null, 'department_id' => null, 'cost_center_id' => null,
                'released_at' => $current->released_at, 'reason' => $reason,
            ]);
        });
    }
}
