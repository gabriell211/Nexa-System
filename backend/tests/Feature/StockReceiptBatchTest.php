<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StockReceiptBatchTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Batch Stock Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password-value',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return [$user, $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken];
    }

    private function as(string $token): static
    {
        Auth::forgetGuards();
        return $this->withToken($token);
    }

    private function setupStock(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Bulk Supplier Tenant', 'slug' => 'bulk-supplier',
        ]);
        [$owner, $token] = $this->token($tenant);
        $supplier = $this->as($token)->postJson('/api/v1/stock/suppliers', [
            'code' => 'BULK-SUP', 'name' => 'Bulk Supplier',
        ])->assertCreated()->json('data.id');
        $itemA = $this->as($token)->postJson('/api/v1/stock/items', [
            'sku' => 'BULK-A', 'name' => 'Toner A', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $itemB = $this->as($token)->postJson('/api/v1/stock/items', [
            'sku' => 'BULK-B', 'name' => 'Toner B', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $location = $this->as($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Batch Stock Room', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');

        return compact('tenant', 'owner', 'token', 'supplier', 'itemA', 'itemB', 'location');
    }

    private function payload(int $supplier, int $itemA, int $itemB, int $location): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'supplier_id' => $supplier,
            'invoice_reference' => 'NF-BATCH-2026',
            'reason' => 'Conferido e recebido fisicamente pelo estoque',
            'items' => [
                [
                    'item_id' => $itemB, 'destination_location_id' => $location,
                    'quantity' => 2, 'lot_number' => 'LOT-B', 'unit_cost_cents' => 7850,
                ],
                [
                    'item_id' => $itemA, 'destination_location_id' => $location,
                    'quantity' => 3, 'lot_number' => 'LOT-A', 'unit_cost_cents' => 6875,
                    'expires_on' => now('UTC')->addMonths(2)->toDateString(),
                ],
            ],
        ];
    }

    public function test_two_lines_are_atomic_scoped_and_preserve_original_order(): void
    {
        extract($this->setupStock());
        $payload = $this->payload($supplier, $itemA, $itemB, $location);

        $created = $this->as($token)->postJson('/api/v1/stock/receipt-batches', $payload)
            ->assertCreated()
            ->assertJsonPath('data.invoice_reference', 'NF-BATCH-2026')
            ->assertJsonPath('data.line_count', 2)
            ->assertJsonPath('data.lines.0.line_number', 1)
            ->assertJsonPath('data.lines.0.receipt.movement.item_id', $itemB)
            ->assertJsonPath('data.lines.0.receipt.unit_cost_cents', 7850)
            ->assertJsonPath('data.lines.1.receipt.movement.item_id', $itemA)
            ->assertJsonPath('data.lines.1.receipt.unit_cost_cents', 6875);
        $batchId = $created->json('data.id');
        $this->as($token)->getJson("/api/v1/stock/receipt-batches/{$batchId}")
            ->assertOk()->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.receipt.supplier.code', 'BULK-SUP');
        $this->assertDatabaseCount('stock_receipt_batches', 1);
        $this->assertDatabaseCount('stock_receipt_batch_lines', 2);
        $this->assertDatabaseCount('stock_receipt_details', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('stock_lots', 2);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $itemB,
            'location_id' => $location, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $itemA,
            'location_id' => $location, 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'entity_type' => 'StockReceiptBatch',
            'entity_id' => $batchId, 'action' => 'stock.receipt_batch.created',
        ]);
    }

    public function test_replay_does_not_double_credit_or_discard_if_supplier_deactivated(): void
    {
        extract($this->setupStock());
        $payload = $this->payload($supplier, $itemA, $itemB, $location);
        $first = $this->as($token)->postJson('/api/v1/stock/receipt-batches', $payload)
            ->assertCreated()->json('data.id');

        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$supplier}", [
            'active' => false,
        ])->assertOk();
        $this->as($token)->postJson('/api/v1/stock/receipt-batches', $payload)
            ->assertOk()->assertJsonPath('data.id', $first);
        $this->as($token)->postJson('/api/v1/stock/receipt-batches', [
            ...$payload, 'items' => [
                [...$payload['items'][0], 'quantity' => 100],
                $payload['items'][1],
            ],
        ])->assertUnprocessable();
        $this->as($token)->postJson('/api/v1/stock/receipt-batches', [
            ...$payload, 'items' => array_reverse($payload['items']),
        ])->assertUnprocessable();
        $this->as($token)->postJson('/api/v1/stock/receipt-batches', [
            ...$payload, 'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_receipt_batches', 1);
        $this->assertDatabaseCount('stock_receipt_details', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(1, DB::table('audit_entries')
            ->where('action', 'stock.receipt_batch.created')->count());
    }

    public function test_bad_second_line_rolls_back_all_previous_entries_and_audit(): void
    {
        extract($this->setupStock());
        $payload = $this->payload($supplier, $itemA, $itemB, $location);
        $foreignTenant = Tenant::query()->create([
            'name' => 'Foreign', 'slug' => 'bulk-foreign',
        ]);
        [, $foreignToken] = $this->token($foreignTenant);
        $foreignLocation = $this->as($foreignToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Foreign Warehouse', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');

        $payload['items'][1]['destination_location_id'] = $foreignLocation;
        $this->as($token)->postJson('/api/v1/stock/receipt-batches', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('stock_receipt_batches', 0);
        $this->assertDatabaseCount('stock_receipt_details', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_lots', 0);
        $this->assertDatabaseCount('stock_lot_balances', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_receipt_batch_lines', 0);
        $this->assertSame(0, DB::table('audit_entries')
            ->where('action', 'stock.receipt_batch.created')->count());
    }

    public function test_validation_bounds_foreign_company_and_permissions(): void
    {
        extract($this->setupStock());
        $payload = $this->payload($supplier, $itemA, $itemB, $location);
        $route = '/api/v1/stock/receipt-batches';

        $this->as($token)->postJson($route, [...$payload, 'items' => []])
            ->assertUnprocessable();
        $this->as($token)->postJson($route, [
            ...$payload, 'items' => array_fill(0, 31, $payload['items'][0]),
        ])->assertUnprocessable();
        $this->as($token)->postJson($route, [
            ...$payload, 'items' => [
                $payload['items'][0],
                [...$payload['items'][1], 'quantity' => 0],
            ],
        ])->assertUnprocessable();
        $this->as($token)->postJson($route, [
            ...$payload, 'items' => [
                $payload['items'][0],
                [...$payload['items'][1], 'expires_on' => 'not-a-date'],
            ],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_receipt_batches', 0);

        $other = Tenant::query()->create([
            'name' => 'Other', 'slug' => 'bulk-other',
        ]);
        [, $otherToken] = $this->token($other);
        $this->as($otherToken)->postJson($route, $payload)->assertUnprocessable();

        $id = $this->as($token)->postJson($route, $payload)->assertCreated()->json('data.id');
        $this->as($otherToken)->getJson("{$route}/{$id}")->assertNotFound();

        [, $techToken] = $this->token($tenant, 'technician');
        $this->as($techToken)->getJson("{$route}/{$id}")->assertForbidden();
        $this->as($techToken)->postJson($route, [
            ...$payload, 'request_id' => (string) Str::uuid(),
        ])->assertForbidden();

        [$warehouse, $warehouseToken] = $this->token($tenant, 'warehouse');
        DB::table('tenant_user')->where('tenant_id', $tenant->id)
            ->where('user_id', $warehouse->id)->update(['customer_scope' => 'assigned']);
        $this->as($warehouseToken)->getJson("{$route}/{$id}")->assertForbidden();
        $this->as($warehouseToken)->postJson($route, $payload)->assertForbidden();

        [, $secondOwnerToken] = $this->token($tenant);
        $this->as($secondOwnerToken)->postJson($route, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('stock_receipt_batches', 1);
    }

    public function test_batch_history_is_paginated_searchable_and_isolated(): void
    {
        extract($this->setupStock());
        $first = $this->payload($supplier, $itemA, $itemB, $location);
        $oldId = $this->as($token)->postJson('/api/v1/stock/receipt-batches', [
            ...$first, 'invoice_reference' => 'NF-ALPHA-2026',
        ])->assertCreated()->json('data.id');

        $newId = $this->as($token)->postJson('/api/v1/stock/receipt-batches', [
            ...$first,
            'request_id' => (string) Str::uuid(),
            'invoice_reference' => 'NF-BETA-2026',
        ])->assertCreated()->json('data.id');

        $this->as($token)->getJson('/api/v1/stock/receipt-batches?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $newId)
            ->assertJsonPath('data.0.supplier.code', 'BULK-SUP');
        $this->as($token)->getJson('/api/v1/stock/receipt-batches?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $oldId);
        $this->as($token)->getJson('/api/v1/stock/receipt-batches?q=alpha')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.invoice_reference', 'NF-ALPHA-2026');
        $this->as($token)->getJson("/api/v1/stock/receipt-batches?supplier_id={$supplier}")
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->as($token)->getJson('/api/v1/stock/receipt-batches?supplier_id=999999')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->as($token)->getJson('/api/v1/stock/receipt-batches?per_page=101')
            ->assertUnprocessable();
        $this->as($token)->getJson('/api/v1/stock/receipt-batches?q='.str_repeat('a', 101))
            ->assertUnprocessable();

        $other = Tenant::query()->create([
            'name' => 'Other', 'slug' => 'bulk-history-other',
        ]);
        [, $otherToken] = $this->token($other);
        $this->as($otherToken)->getJson('/api/v1/stock/receipt-batches')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->as($otherToken)->getJson("/api/v1/stock/receipt-batches/{$newId}")
            ->assertNotFound();
        $this->as($token)->getJson("/api/v1/stock/receipt-batches/{$newId}")
            ->assertOk()->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.receipt.movement.item.sku', 'BULK-B');

        $this->assertDatabaseCount('stock_receipt_batches', 2);
        $this->assertDatabaseCount('stock_receipt_details', 4);
    }

}
