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

final class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role): array
    {
        $user = User::query()->create([
            'name' => ucfirst($role).' Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'stock-count-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return [$user, $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken];
    }

    private function apiPost(string $token, string $path, array $payload): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/api/v1'.$path, $payload);
    }

    private function apiGet(string $token, string $path): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->getJson('/api/v1'.$path);
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Count Provider', 'slug' => 'count-provider']);
        [$owner, $ownerToken] = $this->token($tenant, 'owner');
        [$warehouse, $warehouseToken] = $this->token($tenant, 'warehouse');
        $item = $this->apiPost($ownerToken, '/stock/items', [
            'sku' => 'COUNT-001', 'name' => 'Contador teste', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $location = $this->apiPost($ownerToken, '/stock/locations', [
            'name' => 'Almoxarifado', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        return compact('tenant', 'owner', 'ownerToken', 'warehouse', 'warehouseToken', 'item', 'location');
    }

    private function receipt(string $token, int $item, int $location, int $quantity): void
    {
        $this->apiPost($token, '/stock/movements', [
            'request_id' => (string) Str::uuid(), 'kind' => 'receipt',
            'item_id' => $item, 'source_location_id' => null,
            'destination_location_id' => $location, 'quantity' => $quantity,
            'reason' => 'Entrada de material para inventário',
        ])->assertCreated();
    }

    private function countPayload(int $item, int $location, int $quantity): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'item_id' => $item, 'location_id' => $location,
            'counted_quantity' => $quantity,
            'reason' => 'Contagem física supervisionada',
        ];
    }

    public function test_shortage_requires_second_operator_and_creates_one_atomic_ledger_adjustment(): void
    {
        extract($this->fixture());
        $this->receipt($ownerToken, $item, $location, 10);
        $payload = $this->countPayload($item, $location, 8);
        $count = $this->apiPost($warehouseToken, '/stock/counts', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.expected_quantity', 10)
            ->assertJsonPath('data.difference', -2)->json('data.id');
        $this->apiPost($warehouseToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Divergência conferida',
        ])->assertForbidden();

        $approved = $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Divergência conferida',
        ])->assertOk()->assertJsonPath('data.status', 'approved')->json('data');
        $this->assertNotNull($approved['movement_id']);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $approved['movement_id'], 'kind' => 'issue',
            'item_id' => $item, 'source_location_id' => $location, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'item_id' => $item, 'location_id' => $location, 'quantity' => 8,
        ]);
        $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Divergência conferida',
        ])->assertOk()->assertJsonPath('data.movement_id', $approved['movement_id']);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'entity_type' => 'StockCount', 'action' => 'stock.count.approved',
        ]);
    }

    public function test_overage_is_untracked_and_count_retry_is_idempotent(): void
    {
        extract($this->fixture());
        $this->receipt($ownerToken, $item, $location, 3);
        $payload = $this->countPayload($item, $location, 5);
        $count = $this->apiPost($warehouseToken, '/stock/counts', $payload)
            ->assertCreated()->json('data.id');
        $this->apiPost($warehouseToken, '/stock/counts', $payload)
            ->assertCreated()->assertJsonPath('data.id', $count);
        $this->apiPost($warehouseToken, '/stock/counts', array_merge($payload, ['counted_quantity' => 6]))
            ->assertUnprocessable();
        $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Quantidade excedente conferida',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('stock_balances', [
            'item_id' => $item, 'location_id' => $location, 'quantity' => 5,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'kind' => 'receipt', 'destination_location_id' => $location, 'quantity' => 2,
        ]);
        $this->assertDatabaseCount('stock_lots', 0);
        $this->apiGet($warehouseToken, '/stock/counts?status=approved&item_id='.$item)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $count);
    }

    public function test_supervisor_cannot_review_own_count_or_override_another_review(): void
    {
        extract($this->fixture());
        [$manager, $managerToken] = $this->token($tenant, 'manager');
        $this->receipt($ownerToken, $item, $location, 2);
        $count = $this->apiPost($ownerToken, '/stock/counts', $this->countPayload($item, $location, 2))
            ->assertCreated()->json('data.id');
        $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Tentativa de autoaprovação',
        ])->assertUnprocessable()->assertJsonValidationErrors('count');
        $this->apiPost($managerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Saldo confirmado em segunda conferência',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.movement_id', null);
        $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Tentativa após revisão de outro usuário',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_stale_snapshot_and_reserved_stock_cannot_be_written_off(): void
    {
        extract($this->fixture());
        $this->receipt($ownerToken, $item, $location, 5);
        $stale = $this->apiPost($warehouseToken, '/stock/counts', $this->countPayload($item, $location, 3))
            ->assertCreated()->json('data.id');
        $this->receipt($ownerToken, $item, $location, 1);
        $this->apiPost($ownerToken, '/stock/movements', [
            'request_id' => (string) Str::uuid(), 'kind' => 'issue',
            'item_id' => $item, 'source_location_id' => $location,
            'destination_location_id' => null, 'quantity' => 1,
            'reason' => 'Saída que restaura o saldo anterior',
        ])->assertCreated();
        $this->apiPost($ownerToken, "/stock/counts/{$stale}/approve", [
            'reason' => 'Verificação de saldo alterado',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_counts', ['id' => $stale, 'status' => 'pending']);
        $this->receipt($ownerToken, $item, $location, 1);

        $destination = $this->apiPost($ownerToken, '/stock/locations', [
            'name' => 'Destino', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $this->apiPost($ownerToken, '/stock/shipments', [
            'request_id' => (string) Str::uuid(), 'item_id' => $item,
            'source_location_id' => $location, 'destination_location_id' => $destination,
            'quantity' => 4, 'reason' => 'Reserva para reposição de cliente',
        ])->assertCreated();
        $reserved = $this->apiPost($warehouseToken, '/stock/counts', $this->countPayload($item, $location, 3))
            ->assertCreated()->json('data.id');
        $this->apiPost($ownerToken, "/stock/counts/{$reserved}/approve", [
            'reason' => 'Conferência com remessa reservada',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances', [
            'item_id' => $item, 'location_id' => $location, 'quantity' => 6,
        ]);
        $this->assertDatabaseCount('stock_movements', 4);
    }

    public function test_known_lot_shortage_is_held_for_lot_level_reconciliation(): void
    {
        extract($this->fixture());
        $supplier = $this->apiPost($ownerToken, '/stock/suppliers', [
            'code' => 'COUNT-SUP', 'name' => 'Fornecedor de teste',
        ])->assertCreated()->json('data.id');
        $this->apiPost($ownerToken, '/stock/receipts', [
            'request_id' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'invoice_reference' => 'NF-COUNT', 'lot_number' => 'LOT-COUNT',
            'unit_cost_cents' => 1000, 'item_id' => $item,
            'destination_location_id' => $location, 'quantity' => 3,
            'reason' => 'Entrada documentada para contagem',
        ])->assertCreated();
        $count = $this->apiPost($warehouseToken, '/stock/counts', $this->countPayload($item, $location, 2))
            ->assertCreated()->json('data.id');
        $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Perda precisa identificar o lote',
        ])->assertUnprocessable()->assertJsonValidationErrors('count');
        $this->assertDatabaseHas('stock_lot_balances', ['location_id' => $location, 'quantity' => 3]);
        $this->assertDatabaseHas('stock_counts', ['id' => $count, 'status' => 'pending']);
        $this->assertDatabaseHas('stock_balances', ['item_id' => $item, 'location_id' => $location, 'quantity' => 3]);
    }

    public function test_rejection_and_tenant_isolation_do_not_change_stock(): void
    {
        extract($this->fixture());
        $this->receipt($ownerToken, $item, $location, 2);
        $count = $this->apiPost($warehouseToken, '/stock/counts', $this->countPayload($item, $location, 0))
            ->assertCreated()->json('data.id');
        $other = Tenant::query()->create(['name' => 'Other Provider', 'slug' => 'other-count-provider']);
        [$otherOwner, $otherToken] = $this->token($other, 'owner');
        $this->apiGet($otherToken, "/stock/counts/{$count}")->assertNotFound();
        $this->apiPost($otherToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Tentativa de acesso entre empresas',
        ])->assertNotFound();

        $this->apiPost($ownerToken, "/stock/counts/{$count}/reject", [
            'reason' => 'Contagem sem segunda conferência',
        ])->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.movement_id', null);
        $this->apiPost($ownerToken, "/stock/counts/{$count}/approve", [
            'reason' => 'Revisão tardia inválida',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances', [
            'item_id' => $item, 'location_id' => $location, 'quantity' => 2,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
