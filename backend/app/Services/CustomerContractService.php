<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerContractService
{
    private const AUDIT_FIELDS = [
        'customer_id', 'document_number', 'title', 'provider_unit_name', 'billing_address',
        'billing_timezone', 'effective_on', 'billing_starts_on', 'ends_on', 'active',
        'is_default', 'version', 'notes',
    ];

    public function __construct(private readonly AuditWriter $audit) {}

    public function create(Tenant $tenant, User $actor, array $data): CustomerContract
    {
        return DB::transaction(function () use ($tenant, $actor, $data): CustomerContract {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $customer = Customer::query()->where('tenant_id', $tenant->id)
                ->where('active', true)->lockForUpdate()->findOrFail($data['customer_id']);
            $this->validateDates($data);
            $this->uniqueDocument($tenant, $data['document_number']);
            if (($data['is_default'] ?? false) && !($data['active'] ?? true)) {
                throw ValidationException::withMessages(['is_default' => 'Contrato inativo não pode ser padrão.']);
            }
            if ($data['is_default'] ?? false) {
                $this->clearDefault($tenant, $actor, $customer);
            }
            $contract = CustomerContract::query()->create([
                ...$data,
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $actor->id,
                'version' => 1,
            ]);
            $this->audit->write($tenant, $actor, 'customer_contract.created', $contract,
                null, $contract->only(self::AUDIT_FIELDS));
            return $contract->load('customer');
        });
    }

    public function update(Tenant $tenant, User $actor, int $id, array $data): CustomerContract
    {
        return DB::transaction(function () use ($tenant, $actor, $id, $data): CustomerContract {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $customerId = CustomerContract::query()->where('tenant_id', $tenant->id)->findOrFail($id)->customer_id;
            $customer = Customer::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($customerId);
            $contract = CustomerContract::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($id);
            if ($contract->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'O contrato foi alterado. Recarregue antes de salvar.']);
            }
            unset($data['expected_version']);
            if (!$customer->active && ($data['active'] ?? $contract->active)) {
                throw ValidationException::withMessages(['active' => 'Cliente inativo não pode ter contrato ativo.']);
            }
            $merged = array_merge([
                'effective_on' => $contract->effective_on->toDateString(),
                'billing_starts_on' => $contract->billing_starts_on->toDateString(),
                'ends_on' => $contract->ends_on?->toDateString(),
            ], $data);
            $this->validateDates($merged);
            if (isset($data['document_number'])) {
                $this->uniqueDocument($tenant, $data['document_number'], $contract->id);
            }
            $willBeActive = $data['active'] ?? $contract->active;
            $willBeDefault = $data['is_default'] ?? $contract->is_default;
            if (($data['is_default'] ?? false) && !$willBeActive) {
                throw ValidationException::withMessages(['is_default' => 'Contrato inativo não pode ser padrão.']);
            }
            if ($willBeDefault && !$contract->is_default) {
                $this->clearDefault($tenant, $actor, $customer);
            }
            if (!$willBeActive) {
                $data['is_default'] = false;
            }
            $before = $contract->only(self::AUDIT_FIELDS);
            $contract->fill($data);
            $contract->version++;
            $contract->save();
            $contract->refresh();
            $this->audit->write($tenant, $actor, 'customer_contract.updated', $contract,
                $before, $contract->only(self::AUDIT_FIELDS));
            return $contract->load('customer');
        });
    }

    private function validateDates(array $data): void
    {
        if ($data['billing_starts_on'] < $data['effective_on']) {
            throw ValidationException::withMessages(['billing_starts_on' => 'O faturamento não pode começar antes da vigência.']);
        }
        if (($data['ends_on'] ?? null) !== null && $data['ends_on'] < $data['billing_starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'O fim deve ocorrer após o início do faturamento.']);
        }
    }

    private function uniqueDocument(Tenant $tenant, string $number, ?int $exceptId = null): void
    {
        $exists = CustomerContract::query()->where('tenant_id', $tenant->id)
            ->where('document_number', $number)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['document_number' => 'Documento contratual já cadastrado.']);
        }
    }

    private function clearDefault(Tenant $tenant, User $actor, Customer $customer): void
    {
        $old = CustomerContract::query()->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)->where('active', true)
            ->where('is_default', true)->lockForUpdate()->first();
        if ($old === null) {
            return;
        }
        $before = $old->only(self::AUDIT_FIELDS);
        $old->is_default = false;
        $old->version++;
        $old->save();
        $this->audit->write($tenant, $actor, 'customer_contract.default_replaced', $old,
            $before, $old->only(self::AUDIT_FIELDS));
    }
}
