<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockPurchaseOrder;
use App\Models\StockPurchaseOrderLine;
use App\Models\StockPurchaseReceiptMatch;
use App\Models\StockReceiptDetail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockPurchaseReceiptMatchService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @return array{StockPurchaseReceiptMatch, bool} */
    public function attach(Tenant $tenant, User $actor, int $orderId, array $input): array
    {
        return DB::transaction(function () use ($tenant, $actor, $orderId, $input): array {
            $tenantId = (int) $tenant->id;
            // Serialize concurrent attachments and cancellation of the same order.
            $order = StockPurchaseOrder::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($orderId);
            $line = StockPurchaseOrderLine::query()->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $orderId)->findOrFail($input['purchase_order_line_id']);
            $receipt = StockReceiptDetail::query()->where('tenant_id', $tenantId)
                ->with('movement')->lockForUpdate()->findOrFail($input['receipt_detail_id']);

            $reason = trim($input['variance_reason'] ?? '');
            $reason = $reason === '' ? null : $reason;
            $existing = StockPurchaseReceiptMatch::query()->where('tenant_id', $tenantId)
                ->where('receipt_detail_id', $receipt->id)->first();
            if ($existing !== null) {
                if ((int) $existing->purchase_order_id !== $orderId
                    || (int) $existing->purchase_order_line_id !== (int) $line->id
                    || $existing->variance_reason !== $reason) {
                    throw ValidationException::withMessages([
                        'receipt_detail_id' => 'Este recebimento já foi associado a outra conciliação.',
                    ]);
                }
                return [$existing, false];
            }
            if ($order->status !== 'approved') {
                abort(409, 'Somente pedidos aprovados podem receber conciliação.');
            }
            $planLine = $line->planLine;
            $movement = $receipt->movement;
            if ((int) $receipt->supplier_id !== (int) $order->supplier_id
                || (int) $movement->item_id !== (int) $planLine->item_id
                || (int) $movement->destination_location_id !== (int) $planLine->location_id
                || $movement->kind !== 'receipt') {
                throw ValidationException::withMessages([
                    'receipt_detail_id' => 'Fornecedor, material, destino ou tipo não conferem com a compra.',
                ]);
            }

            $received = (int) StockPurchaseReceiptMatch::query()
                ->where('tenant_id', $tenantId)->where('purchase_order_line_id', $line->id)
                ->sum('quantity');
            if ($received + (int) $movement->quantity > (int) $line->quantity) {
                throw ValidationException::withMessages([
                    'receipt_detail_id' => 'Quantidade recebida supera a linha aprovada do pedido.',
                ]);
            }
            if ((int) $receipt->unit_cost_cents !== (int) $line->unit_cost_cents
                && $reason === null) {
                throw ValidationException::withMessages([
                    'variance_reason' => 'Justifique a diferença entre o custo recebido e o aprovado.',
                ]);
            }

            $match = StockPurchaseReceiptMatch::query()->create([
                'tenant_id' => $tenantId,
                'purchase_order_id' => $orderId,
                'purchase_order_line_id' => $line->id,
                'receipt_detail_id' => $receipt->id,
                'quantity' => $movement->quantity,
                'received_unit_cost_cents' => $receipt->unit_cost_cents,
                'variance_reason' => $reason,
                'created_by_user_id' => $actor->id,
            ]);
            $this->audit->write($tenant, $actor, 'stock.purchase_order.receipt_matched', $order, null, [
                'purchase_order_line_id' => $line->id,
                'receipt_detail_id' => $receipt->id,
                'invoice_reference' => $receipt->invoice_reference,
                'lot_number' => $receipt->lot_number,
                'quantity' => $movement->quantity,
                'approved_unit_cost_cents' => $line->unit_cost_cents,
                'received_unit_cost_cents' => $receipt->unit_cost_cents,
                'variance_reason' => $reason,
            ]);
            return [$match, true];
        }, 3);
    }
}
