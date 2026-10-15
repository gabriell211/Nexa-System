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

final class StockPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Purchasing '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password-value',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return [$user, $user->createToken('test', ['api','tenant:'.$tenant->id])->plainTextToken];
    }

    private function as(string $token): static
    {
        Auth::forgetGuards();
        return $this->withToken($token);
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Purchase Provider', 'slug' => 'purchase-'.Str::lower(Str::random(8)),
        ]);
        [$creator, $creatorToken] = $this->token($tenant, 'warehouse');
        [$manager, $managerToken] = $this->token($tenant, 'manager');
        $item = $this->as($creatorToken)->postJson('/api/v1/stock/items', [
            'sku' => 'PO-'.Str::upper(Str::random(8)), 'name' => 'Toner', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $source = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Source purchasing', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $destination = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Target purchasing', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $policy = $this->as($creatorToken)->putJson(
            "/api/v1/stock/reorder-policies/{$item}/{$destination}", [
                'critical_quantity' => 1, 'safety_quantity' => 2,
                'minimum_quantity' => 3, 'target_quantity' => 8,
            ]
        )->assertCreated()->json('data.id');
        $planResponse = $this->as($creatorToken)->postJson('/api/v1/stock/replenishment-plans', [
            'request_id' => (string) Str::uuid(),
            'due_on' => now('UTC')->addDays(5)->toDateString(),
            'responsible_user_id' => $creator->id,
            'policy_ids' => [$policy],
        ])->assertCreated();
        $plan = $planResponse->json('data.id');
        $line = $planResponse->json('data.items.0.id');
        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $supplier = $this->as($creatorToken)->postJson('/api/v1/stock/suppliers', [
            'code' => 'PURCHASE-SUP', 'name' => 'Supplier of supplies',
        ])->assertCreated()->json('data.id');
        return compact('tenant','creator','creatorToken','manager','managerToken',
            'item','source','destination','plan','line','supplier');
    }

    private function payload(int $plan, int $supplier, int $line, int $quantity = 5): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'plan_id' => $plan, 'supplier_id' => $supplier,
            'notes' => 'Solicitação de compra para suprimentos',
            'items' => [['plan_line_id' => $line, 'quantity' => $quantity, 'unit_cost_cents' => 6875]],
        ];
    }

    public function test_purchase_requires_second_human_approval_and_never_moves_inventory(): void
    {
        extract($this->fixture());
        $payload = $this->payload($plan, $supplier, $line);
        $created = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.total_cents', 34375)
            ->assertJsonPath('data.items.0.plan_line_id', $line)
            ->assertJsonPath('data.items.0.destination.id', $destination);
        $order = $created->json('data.id');
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertOk()->assertJsonPath('data.id', $order);
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$payload, 'items' => [['plan_line_id' => $line, 'quantity' => 4, 'unit_cost_cents' => 6875]],
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertForbidden();
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1, 'review_note' => 'Proposta conferida pelo comprador',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.approved_by.id', $manager->id);
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409);
        $this->as($creatorToken)->getJson("/api/v1/stock/purchase-orders/{$order}")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.supplier.code', 'PURCHASE-SUP');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_shipments', 0);
        $this->assertDatabaseCount('stock_receipt_details', 0);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'action' => 'stock.purchase_order.approved',
            'entity_type' => 'StockPurchaseOrder', 'entity_id' => $order,
        ]);
        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Não cancelar compras abertas',
        ])->assertStatus(409);
    }

    public function test_approved_purchase_and_shipments_share_one_plan_quota(): void
    {
        extract($this->fixture());
        $this->as($creatorToken)->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(), 'kind' => 'receipt',
            'item_id' => $item, 'source_location_id' => null,
            'destination_location_id' => $source, 'quantity' => 20,
            'reason' => 'Estoque físico disponível na origem',
        ])->assertCreated();
        $order = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders',
            $this->payload($plan, $supplier, $line, 5))
            ->assertCreated()->json('data.id');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertOk();
        $shipmentEndpoint = "/api/v1/stock/replenishment-plans/{$plan}/items/{$line}/reserve-shipment";
        $this->as($creatorToken)->postJson($shipmentEndpoint, [
            'request_id' => (string) Str::uuid(), 'source_location_id' => $source,
            'quantity' => 4, 'reason' => 'Separação manual para destino',
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson($shipmentEndpoint, [
            'request_id' => (string) Str::uuid(), 'source_location_id' => $source,
            'quantity' => 3, 'reason' => 'Separação manual para destino',
        ])->assertCreated();
        $second = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders',
            $this->payload($plan, $supplier, $line, 1))
            ->assertCreated()->json('data.id');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$second}/approve", [
            'expected_version' => 1,
        ])->assertUnprocessable();
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/cancel", [
            'expected_version' => 2, 'reason' => 'Compra cancelada pelo gestor',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$second}/approve", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($creatorToken)->getJson('/api/v1/stock/purchase-orders?status=approved')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $second);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_inactive_supplier_duplicate_line_and_foreign_tenant_are_rejected(): void
    {
        extract($this->fixture());
        $original = $this->payload($plan, $supplier, $line, 2);
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$original, 'items' => [$original['items'][0], $original['items'][0]],
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$original, 'items' => [],
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$original, 'items' => [['plan_line_id' => $line, 'quantity' => 1, 'unit_cost_cents' => -1]],
        ])->assertUnprocessable();
        $order = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', $original)
            ->assertCreated()->json('data.id');
        $this->as($creatorToken)->patchJson("/api/v1/stock/suppliers/{$supplier}", [
            'active' => false,
        ])->assertOk();
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_purchase_orders', ['id' => $order, 'status' => 'draft']);
        $other = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'purchase-other']);
        [, $foreign] = $this->token($other);
        $this->as($foreign)->getJson("/api/v1/stock/purchase-orders/{$order}")->assertNotFound();
        $this->as($foreign)->getJson('/api/v1/stock/purchase-orders')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->as($foreign)->postJson('/api/v1/stock/purchase-orders', $original)->assertNotFound();
        [, $technician] = $this->token($tenant, 'technician');
        $this->as($technician)->getJson('/api/v1/stock/purchase-orders')->assertForbidden();
        $this->as($technician)->postJson('/api/v1/stock/purchase-orders', $original)->assertForbidden();
        $this->as($creatorToken)->postJson("/api/v1/stock/purchase-orders/{$order}/cancel", [
            'expected_version' => 1, 'reason' => 'Cancelar rascunho pelo autor',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('stock_purchase_order_lines', 1);
        $this->assertDatabaseCount('stock_receipt_details', 0);
    }

    public function test_manager_cannot_approve_own_purchase_and_filtering_is_scoped(): void
    {
        extract($this->fixture());
        $payload = $this->payload($plan, $supplier, $line, 2);
        $order = $this->as($managerToken)->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertCreated()->json('data.id');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409);
        [, $owner] = $this->token($tenant, 'owner');
        $this->as($owner)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($creatorToken)->getJson("/api/v1/stock/purchase-orders?plan_id={$plan}&supplier_id={$supplier}&per_page=1")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.total_cents', 13750);
        $this->as($creatorToken)->getJson('/api/v1/stock/purchase-orders?per_page=101')
            ->assertUnprocessable();
        $this->as($creatorToken)->getJson('/api/v1/stock/purchase-orders?status=wrong')
            ->assertUnprocessable();
    }
}
