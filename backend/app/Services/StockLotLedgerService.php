<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockLot;
use App\Models\StockLotBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLotAllocation;
use App\Models\StockReceiptDetail;
use App\Models\StockShipment;
use App\Models\StockShipmentLotTransit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Provenance ledger for known inbound lots.
 *
 * Lock order is inherited from InventoryLedgerService: the stock_items row
 * is locked before any lot position, shipment or movement is modified.
 *
 * Legacy stock with no recorded lot remains 'untracked' and can be consumed,
 * but is NEVER silently attributed to a new lot.
 */
final class StockLotLedgerService
{
    /**
     * A reserved SKU cannot include physically expired, known lots.
     * Must be called while the stock_items row lock is held for writes.
     */
    public function eligiblePhysical(int $tenantId, int $itemId, int $locationId, int $physical): int
    {
        $expired = (int) DB::table('stock_lot_balances as balances')
            ->join('stock_lots as lots', 'lots.id', '=', 'balances.lot_id')
            ->where('balances.tenant_id', $tenantId)
            ->where('lots.item_id', $itemId)
            ->where('balances.location_id', $locationId)
            ->whereNotNull('lots.expires_on')
            ->where('lots.expires_on', '<', now('UTC')->toDateString())
            ->sum('balances.quantity');

        return max(0, $physical - $expired);
    }

    public function registerReceipt(StockReceiptDetail $receipt, StockMovement $movement): void
    {
        $tenantId = (int) $movement->tenant_id;
        $lot = StockLot::query()->create([
            'tenant_id' => $tenantId,
            'item_id' => $movement->item_id,
            'receipt_detail_id' => $receipt->id,
            'lot_number' => $receipt->lot_number,
            'expires_on' => $receipt->expires_on?->toDateString(),
            'unit_cost_cents' => $receipt->unit_cost_cents,
        ]);

        StockLotBalance::query()->create([
            'tenant_id' => $tenantId,
            'lot_id' => $lot->id,
            'location_id' => $movement->destination_location_id,
            'quantity' => $movement->quantity,
        ]);
        $this->allocation($movement, (int) $lot->id, (int) $movement->quantity);
    }

    public function applyMovement(StockMovement $movement): void
    {
        if ($movement->source_location_id === null) return;

        $tenantId = (int) $movement->tenant_id;
        $itemId = (int) $movement->item_id;
        $sourceId = (int) $movement->source_location_id;
        $required = (int) $movement->quantity;
        $tracked = (int) StockLotBalance::query()
            ->where('tenant_id', $tenantId)->where('location_id', $sourceId)
            ->whereHas('lot', fn ($query) => $query->where('item_id', $itemId))
            ->sum('quantity');

        $unknown = (int) $movement->source_before - $tracked;
        if ($unknown < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Inconsistência entre saldo físico e saldos por lote.',
            ]);
        }

        // FEFO for tracked material (null expiry is last), FIFO tie break by
        // lot creation/ID. Expired batches cannot be consumed automatically.
        $positions = StockLotBalance::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'stock_lot_balances.lot_id')
            ->where('stock_lot_balances.tenant_id', $tenantId)
            ->where('stock_lots.item_id', $itemId)
            ->where('stock_lot_balances.location_id', $sourceId)
            ->where('stock_lot_balances.quantity', '>', 0)
            ->where(function ($q): void {
                $q->whereNull('stock_lots.expires_on')
                  ->orWhere('stock_lots.expires_on', '>=', now('UTC')->toDateString());
            })
            ->orderByRaw('CASE WHEN stock_lots.expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('stock_lots.expires_on')
            ->orderBy('stock_lots.created_at')
            ->orderBy('stock_lots.id')
            ->select('stock_lot_balances.*')
            ->get();

        foreach ($positions as $position) {
            if ($required === 0) break;
            $take = min($required, (int) $position->quantity);
            $position->update(['quantity' => (int) $position->quantity - $take]);
            if ($movement->destination_location_id !== null) {
                $this->credit(
                    $tenantId, (int) $position->lot_id, (int) $movement->destination_location_id, $take
                );
            }
            $this->allocation($movement, (int) $position->lot_id, $take);
            $required -= $take;
        }

        if ($required > $unknown) {
            throw ValidationException::withMessages([
                'quantity' => 'Material por lote disponível insuficiente. Lotes vencidos não podem ser consumidos.',
            ]);
        }
    }

    public function recordDispatch(StockShipment $shipment, StockMovement $movement): void
    {
        foreach ($movement->lotAllocations()->get() as $entry) {
            $transit = StockShipmentLotTransit::query()->firstOrCreate([
                'tenant_id' => $shipment->tenant_id,
                'shipment_id' => $shipment->id,
                'lot_id' => $entry->lot_id,
            ], ['shipped_quantity' => 0, 'received_quantity' => 0, 'lost_quantity' => 0]);

            $transit->increment('shipped_quantity', $entry->quantity);
        }
    }

    public function recordReceipt(StockShipment $shipment, StockMovement $movement): void
    {
        $remaining = (int) $movement->quantity;
        $transit = StockShipmentLotTransit::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'stock_shipment_lot_transit.lot_id')
            ->where('stock_shipment_lot_transit.tenant_id', $shipment->tenant_id)
            ->where('stock_shipment_lot_transit.shipment_id', $shipment->id)
            ->orderByRaw('CASE WHEN stock_lots.expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('stock_lots.expires_on')->orderBy('stock_lots.id')
            ->select('stock_shipment_lot_transit.*')->get();

        foreach ($transit as $entry) {
            if ($remaining === 0) break;
            $outstanding = (int) $entry->shipped_quantity
                - (int) $entry->received_quantity - (int) $entry->lost_quantity;
            $take = min($remaining, $outstanding);
            if ($take < 1) continue;
            $entry->increment('received_quantity', $take);
            $this->credit(
                (int) $shipment->tenant_id, (int) $entry->lot_id,
                (int) $shipment->destination_location_id, $take
            );
            $this->allocation($movement, (int) $entry->lot_id, $take);
            $remaining -= $take;
        }
        // The remaining quantity is historically untracked stock shipped
        // by this shipment, not a reason to invent provenance.
    }

    public function recordLoss(StockShipment $shipment): void
    {
        StockShipmentLotTransit::query()->where('tenant_id', $shipment->tenant_id)
            ->where('shipment_id', $shipment->id)->get()
            ->each(static function (StockShipmentLotTransit $entry): void {
                $missing = (int) $entry->shipped_quantity
                    - (int) $entry->received_quantity - (int) $entry->lost_quantity;
                if ($missing > 0) $entry->increment('lost_quantity', $missing);
            });
    }

    private function credit(int $tenantId, int $lotId, int $locationId, int $amount): void
    {
        DB::table('stock_lot_balances')->insertOrIgnore([
            'tenant_id' => $tenantId, 'lot_id' => $lotId,
            'location_id' => $locationId, 'quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StockLotBalance::query()
            ->where('tenant_id', $tenantId)->where('lot_id', $lotId)
            ->where('location_id', $locationId)->increment('quantity', $amount);
    }

    private function allocation(StockMovement $movement, int $lotId, int $quantity): void
    {
        StockMovementLotAllocation::query()->create([
            'tenant_id' => $movement->tenant_id,
            'movement_id' => $movement->id,
            'lot_id' => $lotId,
            'quantity' => $quantity,
        ]);
    }
}
