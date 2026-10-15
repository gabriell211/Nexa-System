<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryLedgerService
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly StockLotLedgerService $lots,
    ) {}

    public function move(Tenant $tenant, User $actor, array $input, ?int $specificLotId = null): StockMovement
    {
        // Validator accepts integer-like strings. Normalize IDs before strict
        // comparisons so "1" and 1 cannot evade the same-location guard.
        $input['item_id'] = (int) $input['item_id'];
        $input['quantity'] = (int) $input['quantity'];
        foreach (['source_location_id', 'destination_location_id'] as $key) {
            $input[$key] = $input[$key] === null ? null : (int) $input[$key];
        }

        return DB::transaction(function () use ($tenant, $actor, $input, $specificLotId): StockMovement {
            // PostgreSQL serializes all movements of the same SKU on this row lock.
            // This includes duplicate retries, preventing concurrent last-unit issues.
            $item = StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($input['item_id']);

            $existing = StockMovement::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $input['request_id'])->first();
            if ($existing) {
                $fields = ['kind', 'item_id', 'source_location_id', 'destination_location_id', 'quantity', 'reason'];
                foreach ($fields as $field) {
                    if ($existing->{$field} != $input[$field]) {
                        throw ValidationException::withMessages([
                            'request_id' => 'Identificador de operação reutilizado com valores diferentes.',
                        ]);
                    }
                }
                if ((int) $existing->actor_user_id !== (int) $actor->id) {
                    throw ValidationException::withMessages(['request_id' => 'A operação pertence a outro usuário.']);
                }

                return $existing->load(['item','source','destination','actor:id,name','lotAllocations.lot']);
            }

            if (!$item->active) {
                throw ValidationException::withMessages(['item_id' => 'O item está inativo.']);
            }

            $kind = $input['kind'];
            $sourceId = $input['source_location_id'];
            $destinationId = $input['destination_location_id'];
            if (($kind === 'receipt' && ($sourceId !== null || $destinationId === null)) ||
                ($kind === 'transfer' && ($sourceId === null || $destinationId === null || $sourceId === $destinationId)) ||
                ($kind === 'issue' && ($sourceId === null || $destinationId !== null))) {
                throw ValidationException::withMessages([
                    'kind' => 'A origem e destino não correspondem ao tipo da movimentação.',
                ]);
            }

            $locations = StockLocation::query()->where('tenant_id', $tenant->id)
                ->whereIn('id', array_values(array_filter([$sourceId, $destinationId], static fn ($value) => $value !== null)))
                ->get()->keyBy('id');
            foreach ([$sourceId, $destinationId] as $locationId) {
                if ($locationId !== null && (!$locations->has($locationId) || !$locations->get($locationId)->active)) {
                    throw ValidationException::withMessages(['location_id' => 'Depósito inexistente, de outra empresa ou inativo.']);
                }
            }

            $amount = (int) $input['quantity'];
            $beforeFrom = null; $afterFrom = null;
            $beforeTo = null; $afterTo = null;
            $from = null; $to = null;

            if ($sourceId !== null) {
                $from = $this->lockedBalance($tenant->id, $item->id, (int) $sourceId);
                $beforeFrom = (int) $from->quantity;
                $reserved = app(StockShipmentService::class)->reserved(
                    $tenant->id, $item->id, (int) $sourceId
                );
                if ($beforeFrom - $reserved < $amount) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Saldo disponível insuficiente (estoque reservado para remessas).',
                    ]);
                }
                $afterFrom = $beforeFrom - $amount;
            }
            if ($destinationId !== null) {
                $to = $this->lockedBalance($tenant->id, $item->id, (int) $destinationId);
                $beforeTo = (int) $to->quantity;
                if ($beforeTo > PHP_INT_MAX - $amount) {
                    throw ValidationException::withMessages(['quantity' => 'Limite de quantidade excedido.']);
                }
                $afterTo = $beforeTo + $amount;
            }

            if ($from) $from->update(['quantity' => $afterFrom]);
            if ($to) $to->update(['quantity' => $afterTo]);

            $movement = StockMovement::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $input['request_id'],
                'kind' => $kind, 'item_id' => $item->id,
                'source_location_id' => $sourceId,
                'destination_location_id' => $destinationId,
                'quantity' => $amount,
                'source_before' => $beforeFrom, 'source_after' => $afterFrom,
                'destination_before' => $beforeTo, 'destination_after' => $afterTo,
                'actor_user_id' => $actor->id, 'reason' => $input['reason'],
            ]);
            $this->lots->applyMovement($movement, $specificLotId);
            $this->audit->write($tenant, $actor, 'stock.movement.created', $movement, null,
                $movement->only([
                    'request_id','kind','item_id','source_location_id','destination_location_id',
                    'quantity','source_before','source_after','destination_before','destination_after','reason',
                ]));

            return $movement->load(['item','source','destination','actor:id,name','lotAllocations.lot']);
        }, 3);
    }

    private function lockedBalance(int $tenantId, int $itemId, int $locationId): StockBalance
    {
        // Insert-or-ignore plus unique position index handles first-ever movement.
        DB::table('stock_balances')->insertOrIgnore([
            'tenant_id' => $tenantId, 'item_id' => $itemId,
            'location_id' => $locationId, 'quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return StockBalance::query()->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)->where('location_id', $locationId)
            ->lockForUpdate()->firstOrFail();
    }
}
