<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockReplenishmentPlan;
use App\Models\StockPurchaseOrderLine;
use App\Models\StockReplenishmentPlanItem;
use App\Models\StockShipment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit fulfillment only. Approval never reserves, ships or buys automatically.
 * Lock order is stock SKU first, then plan/line, consistent with other stock writes.
 */
final class StockPlanShipmentService
{
    public function __construct(
        private readonly StockShipmentService $shipments,
        private readonly AuditWriter $audit,
    ) {}

    /** @return array{StockShipment, bool} */
    public function reserve(
        Tenant $tenant, User $actor, int $planId, int $lineId, array $data,
    ): array {
        return DB::transaction(function () use ($tenant, $actor, $planId, $lineId, $data): array {
            $tenantId = (int) $tenant->id;
            $snapshot = StockReplenishmentPlanItem::query()
                ->where('tenant_id', $tenantId)->where('plan_id', $planId)->findOrFail($lineId);
            StockItem::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($snapshot->item_id);
            $plan = StockReplenishmentPlan::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($planId);
            $line = StockReplenishmentPlanItem::query()->where('tenant_id', $tenantId)
                ->where('plan_id', $planId)->lockForUpdate()->findOrFail($lineId);
            if ((int) $line->item_id !== (int) $snapshot->item_id) {
                abort(409, 'O item do plano mudou. Recarregue a proposta.');
            }

            $payload = [
                'request_id' => $data['request_id'],
                'item_id' => (int) $line->item_id,
                'source_location_id' => (int) $data['source_location_id'],
                'destination_location_id' => (int) $line->location_id,
                'quantity' => (int) $data['quantity'],
                'reason' => $data['reason'],
            ];
            $existing = StockShipment::query()->where('tenant_id', $tenantId)
                ->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if ((int) $existing->replenishment_plan_item_id !== $lineId) {
                    throw ValidationException::withMessages([
                        'request_id' => 'UUID utilizado por outra remessa.',
                    ]);
                }
                return [$this->shipments->reserve($tenant, $actor, $payload), false];
            }
            if ($plan->status !== 'approved') {
                abort(409, 'Somente um plano aprovado e ativo pode gerar uma reserva.');
            }

            $committed = (int) StockShipment::query()
                ->where('tenant_id', $tenantId)
                ->where('replenishment_plan_item_id', $lineId)
                ->selectRaw('COALESCE(SUM(quantity - cancelled_quantity), 0) AS total')
                ->value('total');
            $purchased = (int) StockPurchaseOrderLine::query()
                ->join('stock_purchase_orders as orders', 'orders.id', '=', 'stock_purchase_order_lines.purchase_order_id')
                ->where('stock_purchase_order_lines.tenant_id', $tenantId)
                ->where('orders.tenant_id', $tenantId)
                ->where('stock_purchase_order_lines.plan_line_id', $lineId)
                ->where('orders.status', 'approved')
                ->sum('stock_purchase_order_lines.quantity');
            $quantity = (int) $data['quantity'];
            if ($quantity > (int) $line->quantity - $committed - $purchased) {
                throw ValidationException::withMessages([
                    'quantity' => 'A quantidade excede o saldo aprovado ainda não reservado.',
                ]);
            }

            $shipment = $this->shipments->reserve($tenant, $actor, $payload);
            $shipment->replenishment_plan_item_id = $lineId;
            $shipment->save();
            $this->audit->write($tenant, $actor, 'stock.replenishment_plan.shipment_reserved', $plan, null, [
                'plan_line_id' => $lineId, 'shipment_id' => $shipment->id,
                'quantity' => $quantity, 'source_location_id' => (int) $data['source_location_id'],
                'destination_location_id' => (int) $line->location_id,
                'request_id' => $data['request_id'],
            ]);
            return [$shipment, true];
        }, 3);
    }
}
