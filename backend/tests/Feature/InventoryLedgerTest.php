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

final class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function user(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Stock Operator', 'email' => Str::uuid().'@example.test',
            'password' => 'stock-tests-secret-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return [$user, $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken];
    }

    private function basic(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Stock Provider', 'slug' => 'stock-provider']);
        [$actor, $token] = $this->user($tenant);
        $item = $this->withToken($token)->postJson('/api/v1/stock/items', [
            'sku' => 'TONER-001', 'name' => 'Toner preto', 'category' => 'toner', 'unit' => 'un',
        ])->assertCreated()->json('data.id');
        $provider = $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Almoxarifado', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $destination = $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Estoque técnico', 'kind' => 'technician', 'technician_user_id' => $actor->id,
        ])->assertCreated()->json('data.id');

        return compact('tenant', 'actor', 'token', 'item', 'provider', 'destination');
    }

    private function move(
        string $token, string $kind, int $item, ?int $source, ?int $destination,
        int $quantity, ?string $uuid = null,
    ): \Illuminate\Testing\TestResponse {
        // TestCase shares an application across requests; real requests do not
        // share an already-resolved auth guard when bearer identities change.
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/api/v1/stock/movements', [
            'request_id' => $uuid ?? (string) Str::uuid(),
            'kind' => $kind, 'item_id' => $item,
            'source_location_id' => $source,
            'destination_location_id' => $destination,
            'quantity' => $quantity, 'reason' => 'Movimentação autorizada no teste',
        ]);
    }

    public function test_receipt_transfer_and_issue_preserve_independent_balances_and_ledger(): void
    {
        extract($this->basic());
        $this->move($token, 'receipt', $item, null, $provider, 10)->assertCreated()
            ->assertJsonPath('data.destination_before', 0)
            ->assertJsonPath('data.destination_after', 10);
        $this->move($token, 'transfer', $item, $provider, $destination, 4)->assertCreated()
            ->assertJsonPath('data.source_after', 6)
            ->assertJsonPath('data.destination_after', 4);
        $this->move($token, 'issue', $item, $destination, null, 2)->assertCreated()
            ->assertJsonPath('data.source_after', 2);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $provider, 'quantity' => 6,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $destination, 'quantity' => 2,
        ]);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('audit_entries', 6);
        $this->withToken($token)->getJson('/api/v1/stock/movements')
            ->assertOk()->assertJsonCount(3, 'data');
        $this->withToken($token)->getJson('/api/v1/stock/balances')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_insufficient_stock_rolls_back_and_two_requests_cannot_consume_last_unit_twice(): void
    {
        extract($this->basic());
        $this->move($token, 'receipt', $item, null, $provider, 1)->assertCreated();
        $this->move($token, 'issue', $item, $provider, null, 1)->assertCreated();
        $this->move($token, 'issue', $item, $provider, null, 1)->assertUnprocessable();
        $this->move($token, 'transfer', $item, $provider, $destination, 1)->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $provider, 'quantity' => 0,
        ]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_duplicate_request_acknowledges_original_without_reapplying_and_detects_collision(): void
    {
        extract($this->basic());
        $uuid = (string) Str::uuid();
        $first = $this->move($token, 'receipt', $item, null, $provider, 3, $uuid)
            ->assertCreated()->json('data.id');
        $retry = $this->move($token, 'receipt', $item, null, $provider, 3, $uuid)
            ->assertCreated()->json('data.id');
        $this->assertSame($first, $retry);
        $this->move($token, 'receipt', $item, null, $provider, 9, $uuid)->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item, 'location_id' => $provider, 'quantity' => 3,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_invalid_routes_locations_and_payloads_are_rejected(): void
    {
        extract($this->basic());
        $this->move($token, 'transfer', $item, $provider, $provider, 2)->assertUnprocessable();
        $this->move($token, 'receipt', $item, $provider, $destination, 2)->assertUnprocessable();
        $this->move($token, 'issue', $item, null, null, 2)->assertUnprocessable();
        $this->move($token, 'receipt', $item, null, $provider, 0)->assertUnprocessable();

        $other = Tenant::query()->create(['name' => 'Another', 'slug' => 'another']);
        [$foreignActor, $foreignToken] = $this->user($other);
        Auth::forgetGuards();
        $foreignStock = $this->withToken($foreignToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Foreign Stock', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');

        $this->move($token, 'receipt', $item, null, $foreignStock, 2)->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/stock/locations')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_catalog_prevents_duplicate_sku_and_wrong_customer_location_and_technician(): void
    {
        extract($this->basic());
        $this->withToken($token)->postJson('/api/v1/stock/items', [
            'sku' => 'TONER-001', 'name' => 'Duplicado', 'category' => 'toner',
        ])->assertUnprocessable();

        $customer = $tenant->customers()->create(['name' => 'A']);
        $other = $tenant->customers()->create(['name' => 'B']);
        $unit = $other->locations()->create(['tenant_id' => $tenant->id, 'name' => 'B Unit']);
        $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Wrong customer unit', 'kind' => 'customer',
            'customer_id' => $customer->id, 'customer_location_id' => $unit->id,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Unknown technician', 'kind' => 'technician',
            'technician_user_id' => 999999,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Unexpected owner', 'kind' => 'provider',
            'customer_id' => $customer->id,
        ])->assertUnprocessable();
    }

    public function test_warehouse_role_can_move_items_but_technician_cannot(): void
    {
        extract($this->basic());
        [$warehouse, $warehouseToken] = $this->user($tenant, 'warehouse');
        [$technician, $technicianToken] = $this->user($tenant, 'technician');
        $this->move($warehouseToken, 'receipt', $item, null, $provider, 2)->assertCreated();
        $this->move($technicianToken, 'issue', $item, $provider, null, 1)->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($technicianToken)->getJson('/api/v1/stock/balances')->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_restricted_portfolio_must_not_access_provider_stock_even_with_warehouse_role(): void
    {
        extract($this->basic());
        [$warehouse, $warehouseToken] = $this->user($tenant, 'warehouse');
        DB::table('tenant_user')->where('tenant_id', $tenant->id)
            ->where('user_id', $warehouse->id)->update(['customer_scope' => 'assigned']);
        Auth::forgetGuards();
        $this->withToken($warehouseToken)->getJson('/api/v1/stock/items')->assertForbidden();
        $this->withToken($warehouseToken)->getJson('/api/v1/stock/movements')->assertForbidden();
        $this->move($warehouseToken, 'receipt', $item, null, $provider, 5)->assertForbidden();
    }

    public function test_tenant_item_namespace_and_movements_are_isolated(): void
    {
        extract($this->basic());
        $tenantB = Tenant::query()->create(['name' => 'Other provider', 'slug' => 'other']);
        [$memberB, $tokenB] = $this->user($tenantB);
        Auth::forgetGuards();
        $itemB = $this->withToken($tokenB)->postJson('/api/v1/stock/items', [
            'sku' => 'TONER-001', 'name' => 'Different tenant SKU', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $this->assertNotSame($item, $itemB);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/stock/items')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Toner preto');
    }

    public function test_integer_like_string_ids_cannot_bypass_same_location_rule(): void
    {
        extract($this->basic());
        $this->withToken($token)->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(),
            'kind' => 'transfer', 'item_id' => $item,
            'source_location_id' => (string) $provider,
            'destination_location_id' => $provider,
            'quantity' => '1', 'reason' => 'Mixed integer representations',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
