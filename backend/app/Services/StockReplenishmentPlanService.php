<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockReorderPolicy;
use App\Models\StockReplenishmentPlan;
use App\Models\StockShipment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Human-reviewed planning only: no purchase order, reservation or shipment. */
final class StockReplenishmentPlanService
{
    public function __construct(
        private readonly StockReorderAvailability $availability,
        private readonly AuditWriter $audit,
    ) {}

    public function find(int $tenantId, int $planId): StockReplenishmentPlan
    {
        return StockReplenishmentPlan::query()->where('tenant_id', $tenantId)
            ->with([
                'responsible:id,name', 'createdBy:id,name', 'approvedBy:id,name', 'cancelledBy:id,name',
                'items' => static fn ($q) => $q->orderBy('id')
                    ->withSum('shipments as allocated_quantity', DB::raw('quantity - cancelled_quantity'))
                    ->withSum('shipments as shipped_quantity_total', 'shipped_quantity')
                    ->withSum('shipments as received_quantity_total', 'received_quantity')
                    ->withSum('shipments as lost_quantity_total', 'lost_quantity')
                    ->withSum('shipments as cancelled_quantity_total', 'cancelled_quantity'),
                'items.item', 'items.location',
            ])->findOrFail($planId);
    }

    /** @return array{StockReplenishmentPlan, bool} */
    public function create(Tenant $tenant, User $actor, array $data): array
    {
        $policyIds = array_map('intval', $data['policy_ids']);
        sort($policyIds, SORT_NUMERIC);
        $hash = hash('sha256', json_encode([
            (int) $actor->id, $data['due_on'], (int) $data['responsible_user_id'],
            $data['notes'] ?? null, $policyIds,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $actor, $data, $policyIds, $hash): array {
            // The tenant row serializes competing creates, including a reused
            // request_id with a different payload or author.
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $existing = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if (!hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador já usado com dados ou autor diferentes.',
                    ]);
                }
                return [$this->find((int) $tenant->id, (int) $existing->id), false];
            }
            $this->assertResponsible((int) $tenant->id, (int) $data['responsible_user_id']);

            $policies = StockReorderPolicy::query()->where('tenant_id', $tenant->id)
                ->whereIn('id', $policyIds)->get(['id', 'item_id']);
            if ($policies->count() !== count($policyIds)) {
                throw ValidationException::withMessages(['policy_ids' => 'Política inexistente nesta empresa.']);
            }
            $this->lockItems((int) $tenant->id, $policies->pluck('item_id')->all());
            $lines = [];
            $positions = [];
            foreach ($policyIds as $policyId) {
                $snapshot = $this->availability->snapshot((int) $tenant->id, $policyId);
                if ($snapshot === null || !$snapshot['needs_replenishment'] ||
                    $snapshot['suggested_quantity'] < 1 || $snapshot['suggested_quantity'] > 1000000) {
                    throw ValidationException::withMessages([
                        'policy_ids' => 'A necessidade mudou, está inativa ou excede o limite de um milhão de unidades.',
                    ]);
                }
                $policy = $snapshot['policy'];
                $key = $policy->item_id.':'.$policy->location_id;
                if (isset($positions[$key])) {
                    throw ValidationException::withMessages(['policy_ids' => 'Há item/local duplicado.']);
                }
                $positions[$key] = true;
                $lines[] = [
                    'tenant_id' => $tenant->id,
                    'item_id' => $policy->item_id,
                    'location_id' => $policy->location_id,
                    'policy_id' => $policy->id,
                    'origin' => 'suggested',
                    'available_quantity' => $snapshot['available_quantity'],
                    'suggested_quantity' => $snapshot['suggested_quantity'],
                    'critical_quantity' => $policy->critical_quantity,
                    'safety_quantity' => $policy->safety_quantity,
                    'minimum_quantity' => $policy->minimum_quantity,
                    'target_quantity' => $policy->target_quantity,
                    'quantity' => $snapshot['suggested_quantity'],
                    'safety_item' => false,
                ];
            }

            $plan = StockReplenishmentPlan::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $data['request_id'],
                'request_hash' => $hash,
                'status' => 'draft',
                'version' => 1,
                'due_on' => $data['due_on'],
                'responsible_user_id' => $data['responsible_user_id'],
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            foreach ($lines as $line) {
                $plan->items()->create($line);
            }
            $this->audit->write($tenant, $actor, 'stock.replenishment_plan.created', $plan, null, [
                'status' => 'draft', 'due_on' => $data['due_on'],
                'responsible_user_id' => $data['responsible_user_id'],
                'items' => $this->lineSummary($plan),
            ]);
            return [$this->find((int) $tenant->id, (int) $plan->id), true];
        }, 3);
    }

    public function update(Tenant $tenant, User $actor, int $planId, array $data): StockReplenishmentPlan
    {
        return DB::transaction(function () use ($tenant, $actor, $planId, $data): StockReplenishmentPlan {
            $snapshot = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->with('items')->findOrFail($planId);
            $itemIds = $snapshot->items->pluck('item_id')->all();
            if (array_key_exists('items', $data)) {
                $itemIds = array_merge($itemIds, array_column($data['items'], 'item_id'));
            }
            // Inventory and shipment mutations lock stock_items first. Use the
            // same ascending order before locking the plan to avoid deadlocks.
            $this->lockItems((int) $tenant->id, $itemIds);
            $plan = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($planId);
            $this->assertDraftVersion($plan, (int) $data['expected_version']);
            $before = $this->summary($plan);

            $fields = [];
            foreach (['due_on', 'responsible_user_id', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $data[$field];
                }
            }
            if (isset($fields['responsible_user_id'])) {
                $this->assertResponsible((int) $tenant->id, (int) $fields['responsible_user_id']);
            }
            if (array_key_exists('items', $data)) {
                $this->replaceLines($tenant, $plan, $data['items']);
            }
            $this->advance($plan, (int) $data['expected_version'], $fields);
            $this->audit->write($tenant, $actor, 'stock.replenishment_plan.updated', $plan,
                $before, $this->summary($plan));
            return $this->find((int) $tenant->id, $planId);
        }, 3);
    }

    public function approve(Tenant $tenant, User $actor, int $planId, array $data): StockReplenishmentPlan
    {
        return DB::transaction(function () use ($tenant, $actor, $planId, $data): StockReplenishmentPlan {
            $snapshot = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->with('items')->findOrFail($planId);
            $lockedIds = array_values(array_unique($snapshot->items->pluck('item_id')->map(fn ($id) => (int) $id)->all()));
            $this->lockItems((int) $tenant->id, $lockedIds);
            $plan = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($planId);
            $this->assertDraftVersion($plan, (int) $data['expected_version']);
            if ((int) $plan->created_by_user_id === (int) $actor->id) {
                abort(409, 'A aprovação exige outro usuário autorizado.');
            }
            $lines = $plan->items()->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Inclua ao menos um item antes da aprovação.']);
            }
            if (array_diff($lines->pluck('item_id')->map(fn ($id) => (int) $id)->all(), $lockedIds)) {
                abort(409, 'Os itens mudaram durante a revisão. Recarregue o plano.');
            }
            $this->assertResponsible((int) $tenant->id, (int) $plan->responsible_user_id);
            foreach ($lines as $line) {
                $this->assertApprovingPositionActive((int) $tenant->id, (int) $line->item_id, (int) $line->location_id);
                if ($line->origin === 'suggested') {
                    $this->assertCurrentSuggestion((int) $tenant->id, $line);
                }
            }
            $before = $this->summary($plan);
            $this->advance($plan, (int) $data['expected_version'], [
                'status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ]);
            $this->audit->write($tenant, $actor, 'stock.replenishment_plan.approved', $plan,
                $before, $this->summary($plan));
            return $this->find((int) $tenant->id, $planId);
        }, 3);
    }

    public function cancel(Tenant $tenant, User $actor, int $planId, array $data): StockReplenishmentPlan
    {
        return DB::transaction(function () use ($tenant, $actor, $planId, $data): StockReplenishmentPlan {
            $plan = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($planId);
            if ($plan->status === 'cancelled') {
                abort(409, 'Plano já cancelado.');
            }
            if ((int) $plan->version !== (int) $data['expected_version']) {
                abort(409, 'Versão desatualizada. Recarregue o plano.');
            }
            if ($plan->status === 'approved' && StockShipment::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('replenishment_plan_item_id', DB::table('stock_replenishment_plan_items')
                    ->select('id')->where('tenant_id', $tenant->id)->where('plan_id', $planId))
                ->exists()) {
                abort(409, 'Há remessas vinculadas ao plano. Encerre a logística antes de cancelar.');
            }
            $before = $this->summary($plan);
            $this->advance($plan, (int) $data['expected_version'], [
                'status' => 'cancelled',
                'cancelled_by_user_id' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $data['reason'],
            ]);
            $this->audit->write($tenant, $actor, 'stock.replenishment_plan.cancelled', $plan,
                $before, $this->summary($plan));
            return $this->find((int) $tenant->id, $planId);
        }, 3);
    }

    private function replaceLines(Tenant $tenant, StockReplenishmentPlan $plan, array $input): void
    {
        $current = $plan->items()->get()->keyBy('id');
        $seenPositions = [];
        $seenIds = [];
        foreach ($input as $line) {
            $key = (int) $line['item_id'].':'.(int) $line['location_id'];
            if (isset($seenPositions[$key])) {
                throw ValidationException::withMessages(['items' => 'Há item/local duplicado no plano.']);
            }
            $seenPositions[$key] = true;
            $this->assertPositionActive((int) $tenant->id, (int) $line['item_id'], (int) $line['location_id']);
            if (isset($line['id'])) {
                $id = (int) $line['id'];
                if (isset($seenIds[$id]) || !isset($current[$id])) {
                    throw ValidationException::withMessages(['items' => 'Linha repetida ou de outro plano.']);
                }
                $seenIds[$id] = true;
                $existing = $current[$id];
                if ((int) $existing->item_id !== (int) $line['item_id'] ||
                    (int) $existing->location_id !== (int) $line['location_id']) {
                    throw ValidationException::withMessages(['items' => 'Item/local de linha existente é imutável.']);
                }
            } elseif ($current->contains(static fn ($existing): bool =>
                (int) $existing->item_id === (int) $line['item_id'] &&
                (int) $existing->location_id === (int) $line['location_id'])) {
                throw ValidationException::withMessages([
                    'items' => 'Informe o id da linha existente para preservar sua origem.',
                ]);
            }
        }
        foreach ($current as $id => $line) {
            if (!isset($seenIds[(int) $id])) {
                $line->delete();
            }
        }
        foreach ($input as $line) {
            if (isset($line['id'])) {
                $current[(int) $line['id']]->update([
                    'quantity' => $line['quantity'],
                    'safety_item' => $line['safety_item'],
                    'note' => $line['note'] ?? null,
                ]);
            } else {
                $plan->items()->create([
                    'tenant_id' => $tenant->id,
                    'item_id' => $line['item_id'],
                    'location_id' => $line['location_id'],
                    'origin' => 'manual',
                    'quantity' => $line['quantity'],
                    'safety_item' => $line['safety_item'],
                    'note' => $line['note'] ?? null,
                ]);
            }
        }
    }

    private function assertCurrentSuggestion(int $tenantId, $line): void
    {
        $snapshot = $line->policy_id === null ? null
            : $this->availability->snapshot($tenantId, (int) $line->policy_id);
        if ($snapshot === null || !$snapshot['needs_replenishment'] ||
            (int) $snapshot['policy']->item_id !== (int) $line->item_id ||
            (int) $snapshot['policy']->location_id !== (int) $line->location_id ||
            $snapshot['available_quantity'] !== (int) $line->available_quantity ||
            $snapshot['suggested_quantity'] !== (int) $line->suggested_quantity) {
            abort(409, 'A sugestão mudou. Gere um novo plano antes de aprovar.');
        }
        foreach (['critical_quantity', 'safety_quantity', 'minimum_quantity', 'target_quantity'] as $field) {
            if ((int) $snapshot['policy']->{$field} !== (int) $line->{$field}) {
                abort(409, 'A política de reposição mudou. Gere um novo plano antes de aprovar.');
            }
        }
    }

    private function assertResponsible(int $tenantId, int $userId): void
    {
        $active = DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenantId)->where('tenant_user.user_id', $userId)
            ->whereIn('tenant_user.role', ['owner', 'admin', 'manager', 'warehouse'])
            ->where(static fn ($q) => $q->whereIn('tenant_user.role', ['owner', 'admin'])
                ->orWhere('tenant_user.customer_scope', 'all'))
            ->where('users.active', true)->exists();
        if (!$active) {
            throw ValidationException::withMessages([
                'responsible_user_id' => 'Responsável deve ser operador ativo com carteira completa nesta empresa.',
            ]);
        }
    }

    private function assertPositionActive(int $tenantId, int $itemId, int $locationId): void
    {
        $item = StockItem::query()->where('tenant_id', $tenantId)->find($itemId);
        $location = StockLocation::query()->where('tenant_id', $tenantId)->find($locationId);
        if (!$item?->active || !$location?->active) {
            throw ValidationException::withMessages(['items' => 'Item e local devem estar ativos nesta empresa.']);
        }
    }

    private function assertApprovingPositionActive(int $tenantId, int $itemId, int $locationId): void
    {
        $item = StockItem::query()->where('tenant_id', $tenantId)->find($itemId);
        $location = StockLocation::query()->where('tenant_id', $tenantId)->find($locationId);
        if (!$item?->active || !$location?->active) {
            abort(409, 'Item ou local ficou inativo. Revise o plano antes de aprovar.');
        }
    }

    private function lockItems(int $tenantId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            StockItem::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
        }
    }

    private function assertDraftVersion(StockReplenishmentPlan $plan, int $version): void
    {
        if ($plan->status !== 'draft') {
            abort(409, 'Somente rascunhos podem ser alterados ou aprovados.');
        }
        if ((int) $plan->version !== $version) {
            abort(409, 'Versão desatualizada. Recarregue o plano.');
        }
    }

    private function advance(StockReplenishmentPlan $plan, int $expectedVersion, array $fields): void
    {
        $updated = StockReplenishmentPlan::query()->whereKey($plan->id)
            ->where('tenant_id', $plan->tenant_id)->where('version', $expectedVersion)
            ->update([...$fields, 'version' => $expectedVersion + 1, 'updated_at' => now()]);
        if ($updated !== 1) {
            abort(409, 'Versão desatualizada. Recarregue o plano.');
        }
        $plan->refresh();
    }

    private function summary(StockReplenishmentPlan $plan): array
    {
        return [
            'status' => $plan->status, 'version' => $plan->version,
            'due_on' => $plan->due_on?->toDateString(),
            'responsible_user_id' => $plan->responsible_user_id,
            'notes' => $plan->notes,
            'approved_by_user_id' => $plan->approved_by_user_id,
            'cancelled_by_user_id' => $plan->cancelled_by_user_id,
            'review_note' => $plan->review_note,
            'cancel_reason' => $plan->cancel_reason,
            'items' => $this->lineSummary($plan),
        ];
    }

    private function lineSummary(StockReplenishmentPlan $plan): array
    {
        return $plan->items()->orderBy('id')->get()->map(static fn ($line): array => [
            'id' => $line->id, 'item_id' => $line->item_id,
            'location_id' => $line->location_id, 'policy_id' => $line->policy_id,
            'origin' => $line->origin, 'suggested_quantity' => $line->suggested_quantity,
            'quantity' => $line->quantity, 'safety_item' => $line->safety_item,
            'note' => $line->note,
        ])->all();
    }
}
