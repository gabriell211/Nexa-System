<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockShipment;
use App\Models\StockShipmentEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockShipmentService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly AuditWriter $audit,
        private readonly StockLotLedgerService $lots,
    ) {}

    public function reserve(Tenant $tenant, User $actor, array $data): StockShipment
    {
        $itemId = (int) $data['item_id'];
        $sourceId = (int) $data['source_location_id'];
        $destinationId = (int) $data['destination_location_id'];
        $quantity = (int) $data['quantity'];

        return DB::transaction(function () use (
            $tenant, $actor, $data, $itemId, $sourceId, $destinationId, $quantity
        ): StockShipment {
            // Shared with the ledger and event operations: lock by SKU first.
            $item = StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($itemId);
            $existing = StockShipment::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();

            if ($existing !== null) {
                if ((int) $existing->created_by !== (int) $actor->id ||
                    (int) $existing->item_id !== $itemId ||
                    (int) $existing->source_location_id !== $sourceId ||
                    (int) $existing->destination_location_id !== $destinationId ||
                    $existing->quantity !== $quantity || $existing->reason !== $data['reason']) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador já utilizado com dados ou usuário diferentes.',
                    ]);
                }
                return $this->details($existing);
            }
            if (!$item->active || $sourceId === $destinationId) {
                throw ValidationException::withMessages(['source_location_id' => 'Item inativo ou origem igual ao destino.']);
            }

            $places = StockLocation::query()->where('tenant_id', $tenant->id)
                ->whereIn('id', [$sourceId, $destinationId])->where('active', true)->get()->keyBy('id');
            if (!$places->has($sourceId) || !$places->has($destinationId)) {
                throw ValidationException::withMessages(['destination_location_id' => 'Local inexistente, inativo ou de outra empresa.']);
            }
            $physical = (int) (StockBalance::query()
                ->where('tenant_id', $tenant->id)->where('item_id', $itemId)
                ->where('location_id', $sourceId)->value('quantity') ?? 0);

            $eligible = $this->lots->eligiblePhysical($tenant->id, $itemId, $sourceId, $physical);
            if ($eligible - $this->reserved($tenant->id, $itemId, $sourceId) < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Saldo disponível insuficiente para reservar.']);
            }

            $shipment = StockShipment::query()->create([
                'tenant_id' => $tenant->id, 'request_id' => $data['request_id'],
                'item_id' => $itemId, 'source_location_id' => $sourceId,
                'destination_location_id' => $destinationId, 'quantity' => $quantity,
                'status' => 'reserved', 'created_by' => $actor->id, 'reason' => $data['reason'],
            ]);
            $this->audit->write($tenant, $actor, 'stock.shipment.reserved', $shipment, null, [
                'item_id' => $itemId, 'source_location_id' => $sourceId,
                'destination_location_id' => $destinationId, 'quantity' => $quantity,
                'reason' => $data['reason'],
            ]);
            return $this->details($shipment);
        }, 3);
    }

    public function dispatch(Tenant $tenant, User $actor, int $shipmentId, array $data): StockShipment
    {
        return $this->event($tenant, $actor, $shipmentId, 'dispatch', $data);
    }

    public function receive(Tenant $tenant, User $actor, int $shipmentId, array $data): StockShipment
    {
        return $this->event($tenant, $actor, $shipmentId, 'receive', $data);
    }

    public function close(Tenant $tenant, User $actor, int $shipmentId, array $data): StockShipment
    {
        return $this->event($tenant, $actor, $shipmentId, 'close', $data);
    }

    public function reserved(int $tenantId, int $itemId, int $sourceId): int
    {
        // Must be called under the stock_items row lock whenever it is used
        // to make a write decision. Pure GET reporting can use it unlocked.
        return (int) StockShipment::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('source_location_id', $sourceId)
            ->selectRaw('COALESCE(SUM(quantity - shipped_quantity - cancelled_quantity), 0) AS total_reserved')
            ->value('total_reserved');
    }

    private function event(
        Tenant $tenant, User $actor, int $shipmentId, string $kind, array $data,
    ): StockShipment {
        return DB::transaction(function () use ($tenant, $actor, $shipmentId, $kind, $data): StockShipment {
            $snapshot = StockShipment::query()->where('tenant_id', $tenant->id)->findOrFail($shipmentId);
            $item = StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($snapshot->item_id);
            $shipment = StockShipment::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($shipmentId);
            $existing = StockShipmentEvent::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();

            if ($existing !== null) {
                if ((int) $existing->shipment_id !== $shipmentId ||
                    (int) $existing->actor_user_id !== (int) $actor->id ||
                    $existing->kind !== $kind || $existing->reason !== $data['reason'] ||
                    ($kind !== 'close' && $existing->quantity !== (int) $data['quantity'])) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador de evento reutilizado com outro conteúdo.',
                    ]);
                }
                return $this->details($shipment);
            }
            if (in_array($shipment->status, ['received', 'cancelled', 'closed_with_loss', 'closed_partial'], true)) {
                throw ValidationException::withMessages(['shipment' => 'Esta remessa já foi encerrada.']);
            }

            $amount = $kind === 'close' ? 0 : (int) $data['quantity'];
            $before = $shipment->only([
                'status','quantity','shipped_quantity','received_quantity','lost_quantity','cancelled_quantity',
            ]);
            $movement = null;
            if ($kind === 'dispatch') {
                $pending = $shipment->quantity - $shipment->shipped_quantity - $shipment->cancelled_quantity;
                if ($amount > $pending || $amount < 1) {
                    throw ValidationException::withMessages(['quantity' => 'Envio maior que o saldo reservado.']);
                }
                if (!$item->active) {
                    throw ValidationException::withMessages(['item_id' => 'Item inativo.']);
                }
                $shipment->shipped_quantity += $amount;
                // Release only the portion dispatched from the reserved count.
                // The ledger then debits it, preserving other reservations.
                $shipment->save();
                $movement = $this->ledger->move($tenant, $actor, [
                    'request_id'=>$data['request_id'], 'kind'=>'issue',
                    'item_id'=>$shipment->item_id,
                    'source_location_id'=>$shipment->source_location_id,
                    'destination_location_id'=>null,
                    'quantity'=>$amount, 'reason'=>mb_substr('Remessa #'.$shipment->id.': '.$data['reason'], 0, 500),
                ]);
                $this->lots->recordDispatch($shipment, $movement);
            } elseif ($kind === 'receive') {
                $transit = $shipment->shipped_quantity - $shipment->received_quantity - $shipment->lost_quantity;
                if ($amount < 1 || $amount > $transit) {
                    throw ValidationException::withMessages(['quantity' => 'Recebimento supera o material em trânsito.']);
                }
                $destination = StockLocation::query()->where('tenant_id', $tenant->id)
                    ->findOrFail($shipment->destination_location_id);
                if (!$destination->active) {
                    throw ValidationException::withMessages(['destination_location_id' => 'Destino inativo.']);
                }
                $movement = $this->ledger->move($tenant, $actor, [
                    'request_id'=>$data['request_id'], 'kind'=>'receipt',
                    'item_id'=>$shipment->item_id,
                    'source_location_id'=>null,
                    'destination_location_id'=>$shipment->destination_location_id,
                    'quantity'=>$amount, 'reason'=>mb_substr('Recebimento da remessa #'.$shipment->id.': '.$data['reason'], 0, 500),
                ]);
                $this->lots->recordReceipt($shipment, $movement);
                $shipment->received_quantity += $amount;
            } else {
                $shipment->cancelled_quantity = $shipment->quantity - $shipment->shipped_quantity;
                $shipment->lost_quantity = $shipment->shipped_quantity - $shipment->received_quantity;
                // Closing explicitly writes off undistributed reservations and transit shortages.
                $amount = $shipment->lost_quantity;
                $this->lots->recordLoss($shipment);
            }

            $shipment->status = $this->status($shipment);
            $shipment->save();
            StockShipmentEvent::query()->create([
                'tenant_id'=>$tenant->id,'shipment_id'=>$shipment->id,
                'request_id'=>$data['request_id'],'kind'=>$kind,'quantity'=>$amount,
                'actor_user_id'=>$actor->id,'reason'=>$data['reason'],
                'movement_id'=>$movement?->id,
            ]);
            $this->audit->write($tenant, $actor, 'stock.shipment.'.$kind, $shipment, $before, [
                ...$shipment->only([
                    'status','quantity','shipped_quantity','received_quantity','lost_quantity','cancelled_quantity',
                ]),
                'reason'=>$data['reason'],'request_id'=>$data['request_id'],
            ]);
            return $this->details($shipment);
        }, 3);
    }

    private function status(StockShipment $shipment): string
    {
        $pending = $shipment->quantity - $shipment->shipped_quantity - $shipment->cancelled_quantity;
        $transit = $shipment->shipped_quantity - $shipment->received_quantity - $shipment->lost_quantity;
        if ($pending === 0 && $transit === 0) {
            if ($shipment->lost_quantity > 0) return 'closed_with_loss';
            if ($shipment->cancelled_quantity > 0) {
                return $shipment->shipped_quantity === 0 ? 'cancelled' : 'closed_partial';
            }
            return 'received';
        }
        if ($shipment->shipped_quantity === 0) return 'reserved';
        if ($shipment->received_quantity > 0) return 'partially_received';
        if ($pending > 0) return 'partially_dispatched';
        return 'in_transit';
    }

    private function details(StockShipment $shipment): StockShipment
    {
        return $shipment->load([
            'item','source','destination','lotTransit','events'=>fn ($q) => $q->with('actor:id,name')->orderBy('id'),
        ]);
    }
}
