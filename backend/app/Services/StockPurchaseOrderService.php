<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockPurchaseOrder;
use App\Models\StockPurchaseOrderLine;
use App\Models\StockReplenishmentPlan;
use App\Models\StockReplenishmentPlanItem;
use App\Models\StockShipment;
use App\Models\StockSupplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchasing is a human-reviewed commitment, never a ledger movement or payment.
 * Approval and linked shipment reservations lock SKU -> plan in ascending order.
 */
final class StockPurchaseOrderService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function find(int $tenantId, int $orderId): StockPurchaseOrder
    {
        return StockPurchaseOrder::query()->where('tenant_id', $tenantId)
            ->with([
                'supplier:id,code,name,active', 'creator:id,name', 'approver:id,name',
                'lines' => static fn ($q) => $q->orderBy('id'),
                'lines.planLine.item:id,sku,name,unit',
                'lines.planLine.location:id,name',
            ])->withCount('lines')->findOrFail($orderId);
    }

    /** @return array{StockPurchaseOrder, bool} */
    public function create(Tenant $tenant, User $actor, array $data): array
    {
        $items = array_map(static fn (array $line): array => [
            'plan_line_id' => (int) $line['plan_line_id'],
            'quantity' => (int) $line['quantity'],
            'unit_cost_cents' => (int) $line['unit_cost_cents'],
        ], $data['items']);
        usort($items, static fn ($a, $b): int => $a['plan_line_id'] <=> $b['plan_line_id']);
        $hash = hash('sha256', json_encode([
            'actor' => (int) $actor->id,
            'plan_id' => (int) $data['plan_id'],
            'supplier_id' => (int) $data['supplier_id'],
            'notes' => $data['notes'] ?? null,
            'items' => $items,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $actor, $data, $items, $hash): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = StockPurchaseOrder::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_hash, $hash)) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador reutilizado com conteúdo ou autor diferente.',
                    ]);
                }
                return [$this->find((int) $tenant->id, (int) $existing->id), false];
            }
            $plan = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->whereKey($data['plan_id'])->firstOrFail();
            if ($plan->status !== 'approved') {
                abort(409, 'Somente propostas aprovadas permitem pedidos de compra.');
            }
            $supplier = StockSupplier::query()->where('tenant_id', $tenant->id)
                ->whereKey($data['supplier_id'])->firstOrFail();
            if (!$supplier->active) {
                throw ValidationException::withMessages(['supplier_id' => 'Fornecedor inativo.']);
            }
            $lineIds = array_column($items, 'plan_line_id');
            if (count(array_unique($lineIds)) !== count($items)) {
                throw ValidationException::withMessages(['items' => 'Linhas repetidas na compra.']);
            }
            $planLines = StockReplenishmentPlanItem::query()
                ->where('tenant_id', $tenant->id)->where('plan_id', $plan->id)
                ->whereIn('id', $lineIds)->get()->keyBy('id');
            if ($planLines->count() !== count($items)) {
                throw ValidationException::withMessages(['items' => 'Item do plano não pertence à empresa ou à proposta.']);
            }
            foreach ($items as $line) {
                if ($line['quantity'] > (int) $planLines[$line['plan_line_id']]->quantity) {
                    throw ValidationException::withMessages(['items' => 'Quantidade supera a linha aprovada.']);
                }
            }
            $order = StockPurchaseOrder::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $data['request_id'], 'request_hash' => $hash,
                'plan_id' => $plan->id, 'supplier_id' => $supplier->id,
                'status' => 'draft', 'version' => 1, 'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            foreach ($items as $line) {
                $order->lines()->create([
                    ...$line, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
                ]);
            }
            $this->audit->write($tenant, $actor, 'stock.purchase_order.created', $order, null, [
                'plan_id' => $plan->id, 'supplier_id' => $supplier->id,
                'items' => $items, 'status' => 'draft',
            ]);
            return [$this->find((int) $tenant->id, (int) $order->id), true];
        }, 3);
    }

    public function approve(Tenant $tenant, User $actor, int $orderId, array $data): StockPurchaseOrder
    {
        return DB::transaction(function () use ($tenant, $actor, $orderId, $data): StockPurchaseOrder {
            $snapshot = StockPurchaseOrder::query()->where('tenant_id', $tenant->id)
                ->with('lines.planLine')->findOrFail($orderId);
            $ids = $snapshot->lines->pluck('planLine.item_id')->unique()->sort()->values();
            foreach ($ids as $id) {
                StockItem::query()->where('tenant_id', $tenant->id)
                    ->lockForUpdate()->findOrFail($id);
            }
            $plan = StockReplenishmentPlan::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($snapshot->plan_id);
            $order = StockPurchaseOrder::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'draft' || (int) $order->version !== (int) $data['expected_version']) {
                abort(409, 'Pedido não está em revisão ou sua versão está desatualizada.');
            }
            if ((int) $order->created_by_user_id === (int) $actor->id) {
                abort(409, 'A aprovação deve ser registrada por outro usuário de gestão.');
            }
            if ($plan->status !== 'approved') {
                abort(409, 'A proposta original não está aprovada.');
            }
            $supplier = StockSupplier::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($order->supplier_id);
            if (!$supplier->active) {
                throw ValidationException::withMessages(['supplier_id' => 'Fornecedor foi desativado.']);
            }

            $lines = $order->lines()->orderBy('id')->get();
            foreach ($lines as $line) {
                $planLine = StockReplenishmentPlanItem::query()
                    ->where('tenant_id', $tenant->id)->where('plan_id', $plan->id)
                    ->findOrFail($line->plan_line_id);
                if (!$planLine->item?->active || !$planLine->location?->active) {
                    abort(409, 'Material ou destino do plano ficou inativo.');
                }
                $shipped = (int) StockShipment::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('replenishment_plan_item_id', $line->plan_line_id)
                    ->selectRaw('COALESCE(SUM(quantity - cancelled_quantity), 0) AS total')
                    ->value('total');
                $approvedPurchases = (int) StockPurchaseOrderLine::query()
                    ->join('stock_purchase_orders as orders', 'orders.id', '=', 'stock_purchase_order_lines.purchase_order_id')
                    ->where('stock_purchase_order_lines.tenant_id', $tenant->id)
                    ->where('orders.tenant_id', $tenant->id)
                    ->where('stock_purchase_order_lines.plan_line_id', $line->plan_line_id)
                    ->where('orders.status', 'approved')
                    ->sum('stock_purchase_order_lines.quantity');

                if ((int) $line->quantity + $shipped + $approvedPurchases > (int) $planLine->quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'Quantidade já comprometida em remessas ou outros pedidos aprovados.',
                    ]);
                }
            }
            $order->forceFill([
                'status' => 'approved', 'version' => $order->version + 1,
                'approved_by_user_id' => $actor->id, 'approved_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ])->save();
            $this->audit->write($tenant, $actor, 'stock.purchase_order.approved', $order,
                ['status' => 'draft'], [
                    'status' => 'approved', 'version' => $order->version,
                    'plan_id' => $order->plan_id, 'supplier_id' => $order->supplier_id,
                ]);
            return $this->find((int) $tenant->id, $orderId);
        }, 3);
    }

    public function cancel(Tenant $tenant, User $actor, int $orderId, array $data): StockPurchaseOrder
    {
        return DB::transaction(function () use ($tenant, $actor, $orderId, $data): StockPurchaseOrder {
            $order = StockPurchaseOrder::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'cancelled' || (int) $order->version !== (int) $data['expected_version']) {
                abort(409, 'O pedido já foi cancelado ou está desatualizado.');
            }
            $oldStatus = $order->status;
            $order->forceFill([
                'status' => 'cancelled', 'version' => $order->version + 1,
                'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now(),
                'cancel_reason' => $data['reason'],
            ])->save();
            $this->audit->write($tenant, $actor, 'stock.purchase_order.cancelled', $order,
                ['status' => $oldStatus], [
                    'status' => 'cancelled', 'reason' => $data['reason'],
                    'version' => $order->version,
                ]);
            return $this->find((int) $tenant->id, $orderId);
        }, 3);
    }
}
