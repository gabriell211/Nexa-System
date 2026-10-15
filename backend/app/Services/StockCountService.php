<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A count is an observation. Only a second operator can approve the resulting
 * ledger adjustment, and only while the original physical snapshot is current.
 */
final class StockCountService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly StockShipmentService $shipments,
        private readonly AuditWriter $audit,
    ) {}

    public function record(Tenant $tenant, User $actor, array $data): StockCount
    {
        $itemId = (int) $data['item_id'];
        $locationId = (int) $data['location_id'];
        $counted = (int) $data['counted_quantity'];

        return DB::transaction(function () use ($tenant, $actor, $data, $itemId, $locationId, $counted): StockCount {
            // Stock mutations and shipment reservations use this same first lock.
            $item = StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($itemId);
            $existing = StockCount::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if ((int) $existing->item_id !== $itemId ||
                    (int) $existing->location_id !== $locationId ||
                    (int) $existing->counted_quantity !== $counted ||
                    (int) $existing->counted_by_user_id !== (int) $actor->id ||
                    $existing->reason !== $data['reason']) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador de contagem reutilizado com dados ou usuário diferentes.',
                    ]);
                }
                return $this->details($existing);
            }

            $location = StockLocation::query()->where('tenant_id', $tenant->id)
                ->findOrFail($locationId);
            if (!$item->active || !$location->active) {
                throw ValidationException::withMessages([
                    'location_id' => 'Item ou local inativo não pode receber nova contagem.',
                ]);
            }

            $expected = (int) (StockBalance::query()->where('tenant_id', $tenant->id)
                ->where('item_id', $itemId)->where('location_id', $locationId)
                ->value('quantity') ?? 0);
            $observedMovementId = $this->latestMovementId((int) $tenant->id, $itemId, $locationId);
            $count = StockCount::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $data['request_id'],
                'item_id' => $itemId,
                'location_id' => $locationId,
                'expected_quantity' => $expected,
                'counted_quantity' => $counted,
                'observed_movement_id' => $observedMovementId,
                'reason' => $data['reason'],
                'status' => 'pending',
                'counted_by_user_id' => $actor->id,
                'counted_at' => now(),
            ]);
            $this->audit->write($tenant, $actor, 'stock.count.recorded', $count, null, [
                'item_id' => $itemId, 'location_id' => $locationId,
                'expected_quantity' => $expected, 'counted_quantity' => $counted,
                'reason' => $data['reason'], 'status' => 'pending',
            ]);
            return $this->details($count);
        }, 3);
    }

    public function review(Tenant $tenant, User $actor, int $countId, string $decision, string $reason): StockCount
    {
        return DB::transaction(function () use ($tenant, $actor, $countId, $decision, $reason): StockCount {
            $snapshot = StockCount::query()->where('tenant_id', $tenant->id)->findOrFail($countId);
            StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($snapshot->item_id);
            $count = StockCount::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($countId);

            if ($count->status !== 'pending') {
                if ($count->status === $decision &&
                    (int) $count->reviewed_by_user_id === (int) $actor->id &&
                    $count->review_reason === $reason) {
                    return $this->details($count);
                }
                throw ValidationException::withMessages(['count' => 'Esta contagem já foi revisada.']);
            }
            if ((int) $count->counted_by_user_id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'count' => 'A contagem exige revisão por outro usuário supervisor.',
                ]);
            }

            $movement = null;
            if ($decision === 'approved') {
                $physical = (int) (StockBalance::query()->where('tenant_id', $tenant->id)
                    ->where('item_id', $count->item_id)
                    ->where('location_id', $count->location_id)
                    ->value('quantity') ?? 0);
                $latestMovementId = $this->latestMovementId(
                    (int) $tenant->id, (int) $count->item_id, (int) $count->location_id
                );
                if ($physical !== (int) $count->expected_quantity ||
                    $latestMovementId !== $count->observed_movement_id) {
                    throw ValidationException::withMessages([
                        'count' => 'O saldo mudou depois da contagem. Registre uma nova contagem.',
                    ]);
                }
                $difference = (int) $count->counted_quantity - $physical;
                if (abs($difference) > 1000000) {
                    throw ValidationException::withMessages([
                        'count' => 'Diferença acima do limite por ajuste. Faça a reconciliação em etapas.',
                    ]);
                }
                if ($difference < 0) {
                    $reserved = $this->shipments->reserved(
                        (int) $tenant->id, (int) $count->item_id, (int) $count->location_id
                    );
                    if ((int) $count->counted_quantity < $reserved) {
                        throw ValidationException::withMessages([
                            'count' => 'O saldo contado é menor que a quantidade reservada para remessas.',
                        ]);
                    }
                    // An aggregate count cannot identify which known batch was
                    // lost. Keep its provenance untouched until a per-lot count.
                    $tracked = (int) DB::table('stock_lot_balances as balances')
                        ->join('stock_lots as lots', 'lots.id', '=', 'balances.lot_id')
                        ->where('balances.tenant_id', $tenant->id)
                        ->where('lots.item_id', $count->item_id)
                        ->where('balances.location_id', $count->location_id)
                        ->sum('balances.quantity');
                    if ($tracked > 0) {
                        throw ValidationException::withMessages([
                            'count' => 'Há lotes rastreados neste local. A perda exige contagem por lote.',
                        ]);
                    }
                }
                if ($difference !== 0) {
                    $movement = $this->ledger->move($tenant, $actor, [
                        'request_id' => (string) Str::uuid(),
                        'kind' => $difference > 0 ? 'receipt' : 'issue',
                        'item_id' => $count->item_id,
                        'source_location_id' => $difference < 0 ? $count->location_id : null,
                        'destination_location_id' => $difference > 0 ? $count->location_id : null,
                        'quantity' => abs($difference),
                        'reason' => "Ajuste de inventário #{$count->id}: {$reason}",
                    ]);
                }
            }

            $before = $count->only(['status', 'reviewed_by_user_id', 'review_reason', 'movement_id']);
            $count->update([
                'status' => $decision,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_reason' => $reason,
                'movement_id' => $movement?->id,
            ]);
            $this->audit->write($tenant, $actor, "stock.count.{$decision}", $count, $before, [
                'status' => $decision,
                'expected_quantity' => $count->expected_quantity,
                'counted_quantity' => $count->counted_quantity,
                'movement_id' => $movement?->id,
                'reason' => $reason,
            ]);
            return $this->details($count);
        }, 3);
    }

    private function details(StockCount $count): StockCount
    {
        return $count->load(['item', 'location', 'countedBy:id,name', 'reviewedBy:id,name']);
    }

    private function latestMovementId(int $tenantId, int $itemId, int $locationId): ?int
    {
        $id = StockMovement::query()->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where(static fn ($query) => $query->where('source_location_id', $locationId)
                ->orWhere('destination_location_id', $locationId))
            ->max('id');
        return $id === null ? null : (int) $id;
    }
}
