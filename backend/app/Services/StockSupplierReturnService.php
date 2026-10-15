<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockLot;
use App\Models\StockLotBalance;
use App\Models\StockPurchaseReceiptMatch;
use App\Models\StockSupplierReturn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class StockSupplierReturnService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly AuditWriter $audit,
    ) {}

    public function find(int $tenantId, int $returnId): StockSupplierReturn
    {
        return StockSupplierReturn::query()->where('tenant_id', $tenantId)
            ->with(['creator:id,name', 'reviewer:id,name', 'purchaseMatch.receipt'])
            ->findOrFail($returnId);
    }

    /** @return array{StockSupplierReturn,bool} */
    public function create(Tenant $tenant, User $actor, array $data): array
    {
        $reason = trim($data['reason']);
        $hash = hash('sha256', json_encode([
            'creator' => (int) $actor->id,
            'match' => (int) $data['purchase_receipt_match_id'],
            'quantity' => (int) $data['quantity'],
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $actor, $data, $reason, $hash): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = StockSupplierReturn::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if (!hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador já utilizado com parâmetros ou solicitante diferentes.',
                    ]);
                }
                return [$this->find((int) $tenant->id, (int) $existing->id), false];
            }

            $match = StockPurchaseReceiptMatch::query()->where('tenant_id', $tenant->id)
                ->with('purchaseOrder')->findOrFail($data['purchase_receipt_match_id']);
            if ($match->purchaseOrder?->status !== 'approved') {
                abort(409, 'Devoluções exigem uma compra aprovada e conciliada.');
            }
            $used = (int) StockSupplierReturn::query()
                ->where('tenant_id', $tenant->id)
                ->where('purchase_receipt_match_id', $match->id)
                ->whereIn('status', ['pending','completed'])->sum('quantity');
            if ((int) $data['quantity'] > (int) $match->quantity - $used) {
                throw ValidationException::withMessages([
                    'quantity' => 'A quantidade solicitada excede o recebimento ainda não destinado a devoluções.',
                ]);
            }
            $return = StockSupplierReturn::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $data['request_id'],
                'request_hash' => $hash,
                'purchase_receipt_match_id' => $match->id,
                'quantity' => (int) $data['quantity'],
                'reason' => $reason,
                'status' => 'pending',
                'version' => 1,
                'created_by_user_id' => $actor->id,
            ]);
            $this->audit->write($tenant, $actor, 'stock.supplier_return.requested', $return, null, [
                'purchase_receipt_match_id' => $match->id,
                'quantity' => $return->quantity, 'reason' => $reason,
            ]);
            return [$this->find((int) $tenant->id, (int) $return->id), true];
        }, 3);
    }

    public function approve(Tenant $tenant, User $actor, int $returnId, array $data): StockSupplierReturn
    {
        return DB::transaction(function () use ($tenant, $actor, $returnId, $data): StockSupplierReturn {
            // Preserve ledger lock order: item first, then supplier-return state.
            $snapshot = StockSupplierReturn::query()->where('tenant_id', $tenant->id)
                ->with('purchaseMatch.receipt.movement')->findOrFail($returnId);
            $receipt = $snapshot->purchaseMatch?->receipt;
            $itemId = (int) $receipt?->movement?->item_id;
            StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($itemId);

            $return = StockSupplierReturn::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($returnId);
            if ($return->status !== 'pending' || (int) $return->version !== (int) $data['expected_version']) {
                abort(409, 'Solicitação de devolução já processada ou versão desatualizada.');
            }
            if ((int) $return->created_by_user_id === (int) $actor->id) {
                abort(409, 'Outra pessoa autorizada deve confirmar a devolução.');
            }

            $match = StockPurchaseReceiptMatch::query()->where('tenant_id', $tenant->id)
                ->with(['receipt.movement','purchaseOrder'])->findOrFail($return->purchase_receipt_match_id);
            if ($match->purchaseOrder?->status !== 'approved') {
                abort(409, 'Pedido deixou de estar aprovado.');
            }
            if ((int) $match->receipt->movement->item_id !== $itemId) {
                abort(409, 'O material vinculado mudou. Recarregue a devolução.');
            }

            $lot = StockLot::query()->where('tenant_id', $tenant->id)
                ->where('receipt_detail_id', $match->receipt_detail_id)
                ->where('item_id', $itemId)->firstOrFail();
            $locationId = (int) $match->receipt->movement->destination_location_id;
            $availableLot = (int) (StockLotBalance::query()
                ->where('tenant_id', $tenant->id)->where('lot_id', $lot->id)
                ->where('location_id', $locationId)->value('quantity') ?? 0);
            if ($availableLot < (int) $return->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'O lote de origem não possui saldo físico suficiente neste depósito.',
                ]);
            }

            // No financial credit or supplier dispatch occurs automatically.
            $movementRequestId = (string) Uuid::uuid5(
                Uuid::NAMESPACE_URL, 'nexa:supplier-return:'.$tenant->id.':'.$return->request_id
            );
            $movement = $this->ledger->move($tenant, $actor, [
                'request_id' => $movementRequestId,
                'kind' => 'issue',
                'item_id' => $itemId,
                'source_location_id' => $locationId,
                'destination_location_id' => null,
                'quantity' => $return->quantity,
                'reason' => 'Devolução ao fornecedor #'.$return->id.': '.$return->reason,
            ], (int) $lot->id);
            if (!$movement->wasRecentlyCreated) {
                abort(409, 'O identificador de baixa física já havia sido utilizado.');
            }
            $return->forceFill([
                'status' => 'completed', 'version' => $return->version + 1,
                'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                'review_note' => $data['review_note'] ?? null,
                'movement_id' => $movement->id,
            ])->save();
            $this->audit->write($tenant, $actor, 'stock.supplier_return.completed', $return,
                ['status' => 'pending'], [
                    'status' => 'completed', 'movement_id' => $movement->id,
                    'receipt_detail_id' => $match->receipt_detail_id,
                    'lot_id' => $lot->id, 'quantity' => $return->quantity,
                ]);
            return $this->find((int) $tenant->id, (int) $return->id);
        }, 3);
    }

    public function cancel(Tenant $tenant, User $actor, int $returnId, array $data, string $role): StockSupplierReturn
    {
        return DB::transaction(function () use ($tenant, $actor, $returnId, $data, $role): StockSupplierReturn {
            $return = StockSupplierReturn::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($returnId);
            if (!in_array($role, ['owner','admin','manager'], true)
                && (int) $return->created_by_user_id !== (int) $actor->id) {
                abort(403);
            }
            if ($return->status !== 'pending' || (int) $return->version !== (int) $data['expected_version']) {
                abort(409, 'Somente solicitações pendentes podem ser canceladas.');
            }
            $return->forceFill([
                'status' => 'cancelled', 'version' => $return->version + 1,
                'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                'review_note' => $data['reason'],
            ])->save();
            $this->audit->write($tenant, $actor, 'stock.supplier_return.cancelled', $return,
                ['status' => 'pending'], ['status' => 'cancelled', 'reason' => $data['reason']]);
            return $this->find((int) $tenant->id, (int) $return->id);
        }, 3);
    }
}
