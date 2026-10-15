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

final class StockSupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Supplier Operator',
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

    private function createSupplier(string $token, string $code, string $name): int
    {
        return $this->as($token)->postJson('/api/v1/stock/suppliers', [
            'code' => $code,
            'name' => $name,
        ])->assertCreated()->json('data.id');
    }

    public function test_search_state_and_pagination_are_tenant_scoped_and_validated(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Stock Provider', 'slug' => 'supplier-search']);
        [, $token] = $this->token($tenant);
        $alpha = $this->createSupplier($token, 'SUP-ALPHA', 'Fornecedor Alfa');
        $beta = $this->createSupplier($token, 'SUP-BETA', 'Fornecedor Beta');
        $gamma = $this->createSupplier($token, 'SUP-GAMMA', 'Fornecedor Gama');
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$beta}", [
            'active' => false,
        ])->assertOk();

        $this->as($token)->getJson('/api/v1/stock/suppliers?q=alfa')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $alpha);
        $this->as($token)->getJson('/api/v1/stock/suppliers?q=sup-gamma')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $gamma);
        $this->as($token)->getJson('/api/v1/stock/suppliers?active=1')
            ->assertOk()->assertJsonPath('total', 2);
        $this->as($token)->getJson('/api/v1/stock/suppliers?active=0')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $beta);
        $this->as($token)->getJson('/api/v1/stock/suppliers?per_page=1&page=2')
            ->assertOk()->assertJsonPath('total', 3)
            ->assertJsonPath('current_page', 2)->assertJsonPath('data.0.id', $beta);
        $this->as($token)->getJson('/api/v1/stock/suppliers?per_page=101')->assertUnprocessable();
        $this->as($token)->getJson('/api/v1/stock/suppliers?active=maybe')->assertUnprocessable();
        $this->as($token)->getJson('/api/v1/stock/suppliers?q='.str_repeat('a', 101))
            ->assertUnprocessable();
    }

    public function test_updates_are_audited_and_code_is_immutable(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Stock Provider', 'slug' => 'supplier-update']);
        [, $token] = $this->token($tenant);
        $id = $this->createSupplier($token, 'SUP-STABLE', 'Fornecedor Antigo');

        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'name' => 'Fornecedor Atualizado',
            'email' => 'compras@example.test',
            'document' => '12345678000199',
            'active' => false,
        ])->assertOk()->assertJsonPath('data.code', 'SUP-STABLE')
            ->assertJsonPath('data.name', 'Fornecedor Atualizado')
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'entity_type' => 'StockSupplier',
            'entity_id' => $id, 'action' => 'stock.supplier.updated',
        ]);
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'active' => false,
        ])->assertOk();
        $this->assertSame(1, DB::table('audit_entries')
            ->where('action', 'stock.supplier.updated')->count());
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'code' => 'CHANGED',
        ])->assertUnprocessable();
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'tenant_id' => 999,
        ])->assertUnprocessable();
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'name' => 'x',
        ])->assertUnprocessable();
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'email' => 'not-an-email',
        ])->assertUnprocessable();
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'active' => 'maybe',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_suppliers', [
            'id' => $id, 'code' => 'SUP-STABLE',
            'name' => 'Fornecedor Atualizado', 'active' => false,
        ]);
    }

    public function test_deactivation_blocks_new_documented_receipts_without_erasing_history(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Stock Provider', 'slug' => 'supplier-receipts']);
        [, $token] = $this->token($tenant);
        $supplier = $this->createSupplier($token, 'SUP-RECEIPT', 'Distribuidora');
        $item = $this->as($token)->postJson('/api/v1/stock/items', [
            'sku' => 'SUPPLY-RECEIPT', 'name' => 'Toner de teste', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $location = $this->as($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Depósito de recebimento', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $payload = [
            'request_id' => (string) Str::uuid(),
            'supplier_id' => $supplier,
            'item_id' => $item,
            'destination_location_id' => $location,
            'quantity' => 2,
            'invoice_reference' => 'NF-RECEIPT',
            'lot_number' => 'LOT-RECEIPT',
            'unit_cost_cents' => 1000,
            'reason' => 'Recebimento registrado para teste',
        ];

        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$supplier}", [
            'active' => false,
        ])->assertOk();
        $this->as($token)->postJson('/api/v1/stock/receipts', $payload)
            ->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_receipt_details', 0);
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$supplier}", [
            'active' => true,
        ])->assertOk();
        $this->as($token)->postJson('/api/v1/stock/receipts', $payload)
            ->assertCreated();
        $this->as($token)->patchJson("/api/v1/stock/suppliers/{$supplier}", [
            'active' => false,
        ])->assertOk();
        $this->as($token)->getJson('/api/v1/stock/receipts')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.supplier.id', $supplier);
        $this->assertDatabaseCount('stock_receipt_details', 1);
    }

    public function test_foreign_tenants_unauthorized_roles_and_restricted_portfolios_cannot_mutate(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Stock Provider', 'slug' => 'supplier-primary']);
        $other = Tenant::query()->create(['name' => 'Other Stock', 'slug' => 'supplier-other']);
        [, $token] = $this->token($tenant);
        [, $otherToken] = $this->token($other);
        $id = $this->createSupplier($token, 'SUP-PRIMARY', 'Primary');

        $this->as($otherToken)->getJson('/api/v1/stock/suppliers')
            ->assertOk()->assertJsonPath('total', 0);
        $this->as($otherToken)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'active' => false,
        ])->assertNotFound();
        $this->as($token)->patchJson('/api/v1/stock/suppliers/9999999', [
            'active' => false,
        ])->assertNotFound();

        [, $techToken] = $this->token($tenant, 'technician');
        $this->as($techToken)->getJson('/api/v1/stock/suppliers')->assertForbidden();
        $this->as($techToken)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'active' => false,
        ])->assertForbidden();

        [$warehouse, $warehouseToken] = $this->token($tenant, 'warehouse');
        DB::table('tenant_user')->where('tenant_id', $tenant->id)
            ->where('user_id', $warehouse->id)->update(['customer_scope' => 'assigned']);
        $this->as($warehouseToken)->getJson('/api/v1/stock/suppliers')
            ->assertForbidden();
        $this->as($warehouseToken)->patchJson("/api/v1/stock/suppliers/{$id}", [
            'active' => false,
        ])->assertForbidden();
        $this->assertDatabaseHas('stock_suppliers', ['id' => $id, 'active' => true]);
    }
}
