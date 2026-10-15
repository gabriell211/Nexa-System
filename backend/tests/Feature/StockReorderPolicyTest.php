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

final class StockReorderPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Reorder operator', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password-value',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);

        return [$user, $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken];
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'reorder-provider']);
        [$actor, $token] = $this->token($tenant);
        $item = $this->withToken($token)->postJson('/api/v1/stock/items', [
            'sku' => 'TONER-REORDER', 'name' => 'Toner', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $from = $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Provider warehouse', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $to = $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name' => 'Technician warehouse', 'kind' => 'technician',
            'technician_user_id' => $actor->id,
        ])->assertCreated()->json('data.id');

        return compact('tenant', 'actor', 'token', 'item', 'from', 'to');
    }

    private function policy(string $token, int $item, int $location, array $limits = []): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->putJson("/api/v1/stock/reorder-policies/{$item}/{$location}", [
            'critical_quantity' => 1, 'safety_quantity' => 2,
            'minimum_quantity' => 3, 'target_quantity' => 8, ...$limits,
        ]);
    }

    private function receipt(string $token, int $item, int $location, int $quantity): void
    {
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(), 'kind' => 'receipt',
            'item_id' => $item, 'source_location_id' => null,
            'destination_location_id' => $location, 'quantity' => $quantity,
            'reason' => 'Position replenishment test',
        ])->assertCreated();
    }

    public function test_zero_balance_policy_is_critical_then_transitions_through_low_and_ok(): void
    {
        extract($this->fixture());
        $this->policy($token, $item, $from)->assertCreated()
            ->assertJsonPath('data.safety_quantity', 2);
        $this->assertDatabaseMissing('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item, 'location_id' => $from,
        ]);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.physical_quantity', 0)
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.status', 'critical')
            ->assertJsonPath('data.0.suggested_quantity', 8)
            ->assertJsonPath('data.0.item.sku', 'TONER-REORDER')
            ->assertJsonPath('data.0.location.id', $from);

        $this->receipt($token, $item, $from, 2);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs')
            ->assertOk()->assertJsonPath('data.0.status', 'safety')
            ->assertJsonPath('data.0.suggested_quantity', 6);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?status=safety')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->receipt($token, $item, $from, 1);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?status=low')
            ->assertOk()->assertJsonPath('data.0.available_quantity', 3);
        $this->receipt($token, $item, $from, 1);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?needs_only=0&status=ok')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available_quantity', 4)
            ->assertJsonPath('data.0.suggested_quantity', 4);
    }

    public function test_threshold_validation_upsert_audit_and_explicit_removal(): void
    {
        extract($this->fixture());
        $this->policy($token, $item, $from, ['critical_quantity' => 4])->assertUnprocessable();
        $this->policy($token, $item, $from, ['safety_quantity' => -1])->assertUnprocessable();
        $this->policy($token, $item, $from, ['target_quantity' => 1000000001])->assertUnprocessable();
        $this->assertDatabaseCount('stock_reorder_policies', 0);

        $id = $this->policy($token, $item, $from)->assertCreated()->json('data.id');
        $this->policy($token, $item, $from, ['target_quantity' => 12])->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->policy($token, $item, $from, ['target_quantity' => 12])->assertOk();
        $this->assertDatabaseCount('stock_reorder_policies', 1);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'entity_type' => 'StockReorderPolicy',
            'entity_id' => $id, 'action' => 'stock.reorder_policy.updated',
        ]);
        $this->assertSame(2, DB::table('audit_entries')->where('entity_type', 'StockReorderPolicy')->count());
        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/v1/stock/reorder-policies/{$item}/{$from}")
            ->assertNoContent();
        $this->assertDatabaseCount('stock_reorder_policies', 0);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'entity_type' => 'StockReorderPolicy',
            'entity_id' => $id, 'action' => 'stock.reorder_policy.deleted',
        ]);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?needs_only=0')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_and_pagination_apply_before_page_is_selected(): void
    {
        extract($this->fixture());
        $this->policy($token, $item, $from)->assertCreated();
        $this->policy($token, $item, $to)->assertCreated();
        $this->receipt($token, $item, $from, 5);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location_id', $to);
        $this->withToken($token)->getJson("/api/v1/stock/reorder-needs?needs_only=0&location_id={$from}")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'ok');
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?status=critical')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location_id', $to);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?per_page=101')
            ->assertUnprocessable();
    }

    public function test_reservation_and_later_expiry_report_conservative_nonnegative_available(): void
    {
        extract($this->fixture());
        $this->policy($token, $item, $from)->assertCreated();
        $supplier = $this->withToken($token)->postJson('/api/v1/stock/suppliers', [
            'code' => 'SUP-REORDER', 'name' => 'Supplier',
        ])->assertCreated()->json('data.id');
        $receipt = $this->withToken($token)->postJson('/api/v1/stock/receipts', [
            'request_id' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'item_id' => $item, 'destination_location_id' => $from,
            'quantity' => 5, 'invoice_reference' => 'NF-REORDER',
            'lot_number' => 'LOT-REORDER', 'unit_cost_cents' => 100,
            'expires_on' => now('UTC')->addDays(2)->toDateString(),
            'reason' => 'Tracked inbound lot',
        ])->assertCreated()->json('data.id');
        $this->receipt($token, $item, $from, 3); // Untracked legacy stock.
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/stock/shipments', [
            'request_id' => (string) Str::uuid(), 'item_id' => $item,
            'source_location_id' => $from, 'destination_location_id' => $to,
            'quantity' => 4, 'reason' => 'Reserve before lot expiry',
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?needs_only=0')
            ->assertOk()->assertJsonPath('data.0.physical_quantity', 8)
            ->assertJsonPath('data.0.reserved_quantity', 4)
            ->assertJsonPath('data.0.expired_quantity', 0)
            ->assertJsonPath('data.0.available_quantity', 4);

        DB::table('stock_lots')->where('receipt_detail_id', $receipt)
            ->update(['expires_on' => now('UTC')->subDay()->toDateString()]);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs')
            ->assertOk()->assertJsonPath('data.0.physical_quantity', 8)
            ->assertJsonPath('data.0.reserved_quantity', 4)
            ->assertJsonPath('data.0.expired_quantity', 5)
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.status', 'critical')
            ->assertJsonPath('data.0.suggested_quantity', 8);
    }

    public function test_tenant_isolation_roles_and_full_portfolio_guard(): void
    {
        extract($this->fixture());
        $this->policy($token, $item, $from)->assertCreated();
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-reorder']);
        [, $foreignToken] = $this->token($other);
        Auth::forgetGuards();
        $this->withToken($foreignToken)->getJson('/api/v1/stock/reorder-needs?needs_only=0')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->policy($foreignToken, $item, $from)->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($foreignToken)->deleteJson("/api/v1/stock/reorder-policies/{$item}/{$from}")
            ->assertNotFound();

        [$warehouse, $warehouseToken] = $this->token($tenant, 'warehouse');
        $this->policy($warehouseToken, $item, $to)->assertCreated();
        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $warehouse->id)
            ->update(['customer_scope' => 'assigned']);
        Auth::forgetGuards();
        $this->withToken($warehouseToken)->getJson('/api/v1/stock/reorder-needs')
            ->assertForbidden();
        $this->policy($warehouseToken, $item, $from)->assertForbidden();

        [, $technicianToken] = $this->token($tenant, 'technician');
        Auth::forgetGuards();
        $this->withToken($technicianToken)->getJson('/api/v1/stock/reorder-needs')
            ->assertForbidden();
        $this->policy($technicianToken, $item, $from)->assertForbidden();
    }

    public function test_inactive_item_or_location_never_generates_a_reorder_suggestion(): void
    {
        extract($this->fixture());
        $this->policy($token, $item, $from)->assertCreated();

        DB::table('stock_items')->where('id', $item)->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?needs_only=0&status=inactive')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.0.suggested_quantity', 0);
        $this->policy($token, $item, $from)->assertUnprocessable();

        DB::table('stock_items')->where('id', $item)->update(['active' => true]);
        DB::table('stock_locations')->where('id', $from)->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/stock/reorder-needs?needs_only=0')
            ->assertOk()->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.0.suggested_quantity', 0);
        $this->policy($token, $item, $from)->assertUnprocessable();
    }
}
