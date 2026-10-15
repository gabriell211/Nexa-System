<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockReceiptBatch;
use App\Models\StockSupplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class StockReceiptBatchService
{
    public function __construct(
        private readonly StockDocumentedReceiptService $receipts,
        private readonly AuditWriter $audit,
    ) {}

    public function find(int $tenantId, int $batchId): StockReceiptBatch
    {
        return StockReceiptBatch::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'lines' => static fn ($query) => $query->orderBy('line_number'),
                'lines.receipt.supplier',
                'lines.receipt.movement.item',
                'lines.receipt.movement.destination',
            ])->findOrFail($batchId);
    }

    /** @return array{StockReceiptBatch, bool} */
    public function create(Tenant $tenant, User $actor, array $data): array
    {
        // Canonicalize integer-like validated values and nullable optional fields.
        // Preserve line order: reordering lines is not the same operation.
        $items = array_map(static fn (array $line): array => [
            'item_id' => (int) $line['item_id'],
            'destination_location_id' => (int) $line['destination_location_id'],
            'quantity' => (int) $line['quantity'],
            'lot_number' => $line['lot_number'],
            'expires_on' => $line['expires_on'] ?? null,
            'unit_cost_cents' => (int) $line['unit_cost_cents'],
        ], $data['items']);
        $hash = hash('sha256', json_encode([
            'actor_id' => (int) $actor->id,
            'supplier_id' => (int) $data['supplier_id'],
            'invoice_reference' => $data['invoice_reference'],
            'reason' => $data['reason'],
            'items' => $items,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $actor, $data, $items, $hash): array {
            // Serialize concurrent retries for one tenant across PostgreSQL workers.
            // The unique (tenant_id, request_id) index is the final database guard.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $existing = StockReceiptBatch::query()
                ->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_hash, $hash)) {
                    throw ValidationException::withMessages([
                        'request_id' => 'Identificador já usado com outro conteúdo ou autor.',
                    ]);
                }
                return [$this->find((int) $tenant->id, (int) $existing->id), false];
            }

            // Use the same supplier -> sorted SKU lock order as single documented receipts.
            $supplier = StockSupplier::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->find($data['supplier_id']);
            if (!$supplier?->active) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Fornecedor inexistente ou inativo nesta empresa.',
                ]);
            }
            $itemIds = array_values(array_unique(array_column($items, 'item_id')));
            sort($itemIds, SORT_NUMERIC);
            foreach ($itemIds as $itemId) {
                StockItem::query()->where('tenant_id', $tenant->id)
                    ->lockForUpdate()->findOrFail($itemId);
            }

            $batch = StockReceiptBatch::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $data['request_id'],
                'request_hash' => $hash,
                'actor_user_id' => $actor->id,
                'supplier_id' => $supplier->id,
                'invoice_reference' => $data['invoice_reference'],
                'line_count' => count($items),
            ]);

            foreach ($items as $index => $line) {
                // Stable per-line UUIDs also protect the underlying movement ledger.
                $lineRequestId = (string) Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'nexa:receipt-batch:'.$tenant->id.':'.$data['request_id'].':'.$index
                );
                $detail = $this->receipts->create($tenant, $actor, [
                    ...$line,
                    'request_id' => $lineRequestId,
                    'supplier_id' => $supplier->id,
                    'invoice_reference' => $data['invoice_reference'],
                    'reason' => $data['reason'],
                ]);
                $batch->lines()->create([
                    'tenant_id' => $tenant->id,
                    'line_number' => $index + 1,
                    'receipt_detail_id' => $detail->id,
                ]);
            }

            $this->audit->write($tenant, $actor, 'stock.receipt_batch.created', $batch, null, [
                'supplier_id' => $supplier->id,
                'invoice_reference' => $data['invoice_reference'],
                'line_count' => count($items),
                'receipt_detail_ids' => $batch->lines()->orderBy('line_number')->pluck('receipt_detail_id')->all(),
            ]);
            return [$this->find((int) $tenant->id, (int) $batch->id), true];
        }, 3);
    }
}
