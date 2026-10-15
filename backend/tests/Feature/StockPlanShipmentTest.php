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

final class StockPlanShipmentTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Plan shipping '.Str::random(6),
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

    private function fixture(bool $approve = true): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Plan Shipping', 'slug' => 'plan-ship-'.Str::lower(Str::random(8)),
        ]);
        [$creator, $creatorToken] = $this->token($tenant, 'warehouse');
        [$manager, $managerToken] = $this->token($tenant, 'manager');
        $item = $this->as($creatorToken)->postJson('/api/v1/stock/items', [
            'sku' => 'PS-'.Str::upper(Str::random(8)),
            'name' => 'Toner reserva', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $source = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Almoxarifado central', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $destination = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Depósito destino', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $this->as($creatorToken)->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(),
            'kind' => 'receipt', 'item_id' => $item,
            'source_location_id' => null, 'destination_location_id' => $source,
            'quantity' => 20, 'reason' => 'Estoque disponível em origem',
        ])->assertCreated();
        $policy = $this->as($creatorToken)->putJson(
            "/api/v1/stock/reorder-policies/{$item}/{$destination}", [
                'critical_quantity' => 1, 'safety_quantity' => 2,
                'minimum_quantity' => 3, 'target_quantity' => 8,
            ]
        )->assertCreated()->json('data.id');
        $created = $this->as($creatorToken)->postJson('/api/v1/stock/replenishment-plans', [
            'request_id' => (string) Str::uuid(),
            'due_on' => now('UTC')->addDays(5)->toDateString(),
            'responsible_user_id' => $creator->id,
            'policy_ids' => [$policy],
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
        $plan = $created->json('data.id');
        $line = $created->json('data.items.0.id');
        if ($approve) {
            $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
                'expected_version' => 1, 'review_note' => 'Conferido por segunda pessoa',
            ])->assertOk()->assertJsonPath('data.status', 'approved');
        }
        return compact('tenant','creator','creatorToken','manager','managerToken',
            'item','source','destination','plan','line');
    }

    private function payload(int $source, int $quantity = 5, ?string $requestId = null): array
    {
        return [
            'request_id' => $requestId ?? (string) Str::uuid(),
            'source_location_id' => $source,
            'quantity' => $quantity,
            'reason' => 'Reserva para reposição aprovada',
        ];
    }

    private function endpoint(int $plan, int $line): string
    {
        return "/api/v1/stock/replenishment-plans/{$plan}/items/{$line}/reserve-shipment";
    }

    public function test_only_approved_plan_reserves_and_replays_without_double_allocation(): void
    {
        extract($this->fixture(false));
        $endpoint = $this->endpoint($plan, $line);
        $payload = $this->payload($source);
        $this->as($creatorToken)->postJson($endpoint, $payload)->assertStatus(409);
        $this->assertDatabaseCount('stock_shipments', 0);
        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 1,
        ])->assertOk();

        $shipmentId = $this->as($creatorToken)->postJson($endpoint, $payload)
            ->assertCreated()->assertJsonPath('data.destination_location_id', $destination)
            ->assertJsonPath('data.replenishment_plan_item_id', $line)
            ->assertJsonPath('data.reserved_quantity', 5)->json('data.id');
        $this->as($creatorToken)->postJson($endpoint, $payload)
            ->assertOk()->assertJsonPath('data.id', $shipmentId);
        $this->as($creatorToken)->postJson($endpoint, [
            ...$payload, 'quantity' => 4,
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson('/api/v1/stock/shipments', [
            'request_id' => $payload['request_id'],
            'item_id' => $item, 'source_location_id' => $source,
            'destination_location_id' => $destination,
            'quantity' => 5, 'reason' => $payload['reason'],
        ])->assertCreated()->assertJsonPath('data.id', $shipmentId);

        $this->as($creatorToken)->getJson("/api/v1/stock/replenishment-plans/{$plan}")
            ->assertOk()->assertJsonPath('data.items.0.allocated_quantity', 5)
            ->assertJsonPath('data.items.0.remaining_quantity', 3);
        $this->as($creatorToken)->getJson("/api/v1/stock/shipments?replenishment_plan_item_id={$line}")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shipmentId);

        $this->as($creatorToken)->postJson($endpoint, $this->payload($source, 4))
            ->assertUnprocessable();
        $this->as($creatorToken)->postJson($endpoint, $this->payload($source, 3))
            ->assertCreated();
        $this->as($creatorToken)->postJson($endpoint, $this->payload($source, 1))
            ->assertUnprocessable();
        $this->assertDatabaseCount('stock_shipments', 2);
        $this->assertSame(2, DB::table('audit_entries')
            ->where('action', 'stock.replenishment_plan.shipment_reserved')->count());
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $source, 'quantity' => 20,
        ]);
        $this->assertDatabaseMissing('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $destination,
        ]);

        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Cancelar com remessa vinculada',
        ])->assertStatus(409);
    }

    public function test_dispatch_and_receipt_affect_ledger_only_after_explicit_events(): void
    {
        extract($this->fixture());
        $shipmentId = $this->as($creatorToken)->postJson(
            $this->endpoint($plan, $line), $this->payload($source, 3)
        )->assertCreated()->json('data.id');
        $this->as($creatorToken)->getJson("/api/v1/stock/replenishment-plans/{$plan}")
            ->assertOk()->assertJsonPath('data.items.0.reserved_quantity', 3)
            ->assertJsonPath('data.items.0.in_transit_quantity', 0)
            ->assertJsonPath('data.items.0.received_quantity', 0);
        $this->as($creatorToken)->postJson("/api/v1/stock/shipments/{$shipmentId}/dispatch", [
            'request_id' => (string) Str::uuid(),
            'quantity' => 3, 'reason' => 'Expedição física confirmada',
        ])->assertOk()->assertJsonPath('data.shipped_quantity', 3);
        $this->as($creatorToken)->getJson("/api/v1/stock/replenishment-plans/{$plan}")
            ->assertOk()->assertJsonPath('data.items.0.reserved_quantity', 0)
            ->assertJsonPath('data.items.0.shipped_quantity', 3)
            ->assertJsonPath('data.items.0.in_transit_quantity', 3);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $source, 'quantity' => 17,
        ]);
        $this->as($creatorToken)->postJson("/api/v1/stock/shipments/{$shipmentId}/receive", [
            'request_id' => (string) Str::uuid(),
            'quantity' => 3, 'reason' => 'Recebimento físico confirmado',
        ])->assertOk()->assertJsonPath('data.received_quantity', 3);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id, 'item_id' => $item,
            'location_id' => $destination, 'quantity' => 3,
        ]);
        $this->as($creatorToken)->getJson("/api/v1/stock/replenishment-plans/{$plan}")
            ->assertOk()->assertJsonPath('data.items.0.allocated_quantity', 3)
            ->assertJsonPath('data.items.0.in_transit_quantity', 0)
            ->assertJsonPath('data.items.0.received_quantity', 3)
            ->assertJsonPath('data.items.0.lost_quantity', 0);
        $this->as($creatorToken)->getJson("/api/v1/stock/shipments?replenishment_plan_id={$plan}")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.replenishment_plan_id', $plan)
            ->assertJsonPath('data.0.replenishment_plan_item_id', $line);
        $this->as($creatorToken)->getJson("/api/v1/stock/shipments?replenishment_plan_id=999999")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->as($creatorToken)->getJson('/api/v1/stock/shipments?replenishment_plan_id=bad')
            ->assertUnprocessable();
    }

    public function test_cross_tenant_line_and_roles_are_rejected_and_bad_payload_is_atomic(): void
    {
        extract($this->fixture());
        $endpoint = $this->endpoint($plan, $line);
        $other = Tenant::query()->create([
            'name' => 'Other', 'slug' => 'another-shipping-plan',
        ]);
        [, $foreign] = $this->token($other);
        $this->as($foreign)->postJson($endpoint, $this->payload($source))
            ->assertNotFound();
        $this->as($foreign)->getJson("/api/v1/stock/replenishment-plans/{$plan}")
            ->assertNotFound();
        $this->as($creatorToken)->postJson($this->endpoint($plan, 999999), $this->payload($source))
            ->assertNotFound();
        $this->as($creatorToken)->postJson($endpoint, $this->payload($destination))
            ->assertUnprocessable();
        $this->as($creatorToken)->postJson($endpoint, $this->payload($source, 0))
            ->assertUnprocessable();
        $this->as($creatorToken)->postJson($endpoint, [
            ...$this->payload($source), 'request_id' => 'bad-uuid',
        ])->assertUnprocessable();

        [, $tech] = $this->token($tenant, 'technician');
        $this->as($tech)->postJson($endpoint, $this->payload($source))
            ->assertForbidden();
        [$warehouse, $warehouseToken] = $this->token($tenant, 'warehouse');
        DB::table('tenant_user')->where('tenant_id', $tenant->id)
            ->where('user_id', $warehouse->id)->update(['customer_scope' => 'assigned']);
        $this->as($warehouseToken)->postJson($endpoint, $this->payload($source))
            ->assertForbidden();
        $this->assertDatabaseCount('stock_shipments', 0);
    }

    public function test_cancelling_unallocated_approved_plan_remains_possible(): void
    {
        extract($this->fixture());
        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Necessidade deixou de existir',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($creatorToken)->postJson($this->endpoint($plan, $line), $this->payload($source))
            ->assertStatus(409);
    }
}
