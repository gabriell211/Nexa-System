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

final class StockReplenishmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Planner '.Str::random(6), 'email' => Str::uuid().'@example.test',
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

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'replenishment-provider']);
        [$creator, $creatorToken] = $this->token($tenant, 'warehouse');
        [$reviewer, $reviewerToken] = $this->token($tenant, 'manager');
        $item = $this->as($creatorToken)->postJson('/api/v1/stock/items', [
            'sku' => 'TONER-PLAN', 'name' => 'Toner', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $manualItem = $this->as($creatorToken)->postJson('/api/v1/stock/items', [
            'sku' => 'FUSER-PLAN', 'name' => 'Fuser', 'category' => 'part',
        ])->assertCreated()->json('data.id');
        $location = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Provider warehouse', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $policy = $this->as($creatorToken)->putJson("/api/v1/stock/reorder-policies/{$item}/{$location}", [
            'critical_quantity' => 1, 'safety_quantity' => 2,
            'minimum_quantity' => 3, 'target_quantity' => 8,
        ])->assertCreated()->json('data.id');
        return compact('tenant', 'creator', 'creatorToken', 'reviewer', 'reviewerToken',
            'item', 'manualItem', 'location', 'policy');
    }

    private function createPlan(string $token, int $responsibleId, array $policyIds, array $extra = [])
    {
        return $this->as($token)->postJson('/api/v1/stock/replenishment-plans', [
            'request_id' => (string) Str::uuid(),
            'due_on' => now('UTC')->addDays(5)->toDateString(),
            'responsible_user_id' => $responsibleId,
            'notes' => 'Planejamento de estoque',
            'policy_ids' => $policyIds,
            ...$extra,
        ]);
    }

    public function test_suggestion_can_be_adjusted_with_manual_safety_item_and_second_user_approval(): void
    {
        extract($this->fixture());
        $created = $this->createPlan($creatorToken, $reviewer->id, [$policy])
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.items.0.origin', 'suggested')
            ->assertJsonPath('data.items.0.available_quantity', 0)
            ->assertJsonPath('data.items.0.suggested_quantity', 8);
        $plan = $created->json('data.id');
        $line = $created->json('data.items.0.id');
        $this->as($creatorToken)->patchJson("/api/v1/stock/replenishment-plans/{$plan}", [
            'expected_version' => 1,
            'due_on' => now('UTC')->addDays(7)->toDateString(),
            'items' => [
                ['id' => $line, 'item_id' => $item, 'location_id' => $location,
                    'quantity' => 10, 'safety_item' => true, 'note' => 'Reserva técnica'],
                ['item_id' => $manualItem, 'location_id' => $location,
                    'quantity' => 2, 'safety_item' => true, 'note' => 'Inclusão manual'],
            ],
        ])->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 10)
            ->assertJsonPath('data.items.1.origin', 'manual')
            ->assertJsonPath('data.items.1.safety_item', true);
        $this->as($creatorToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 2,
        ])->assertForbidden();
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 2, 'review_note' => 'Quantidades conferidas',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.approved_by.id', $reviewer->id);
        $this->as($creatorToken)->patchJson("/api/v1/stock/replenishment-plans/{$plan}", [
            'expected_version' => 3, 'notes' => 'Inalterável',
        ])->assertStatus(409);
        $this->as($reviewerToken)->getJson('/api/v1/stock/replenishment-plans?status=approved&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.items_count', 2);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id,
            'entity_type' => 'StockReplenishmentPlan',
            'entity_id' => $plan,
            'action' => 'stock.replenishment_plan.approved',
        ]);
        $this->assertDatabaseCount('stock_shipments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_reused_request_is_idempotent_and_stale_suggestion_or_version_conflicts(): void
    {
        extract($this->fixture());
        $requestId = (string) Str::uuid();
        $plan = $this->createPlan($creatorToken, $reviewer->id, [$policy], [
            'request_id' => $requestId,
        ])->assertCreated()->json('data.id');
        $this->createPlan($creatorToken, $reviewer->id, [$policy], [
            'request_id' => $requestId,
        ])->assertOk()->assertJsonPath('data.id', $plan);
        $this->createPlan($creatorToken, $reviewer->id, [], [
            'request_id' => $requestId,
        ])->assertUnprocessable();
        $this->createPlan($reviewerToken, $reviewer->id, [$policy], [
            'request_id' => $requestId,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_replenishment_plans', 1);
        $this->as($creatorToken)->patchJson("/api/v1/stock/replenishment-plans/{$plan}", [
            'expected_version' => 7, 'notes' => 'Versão velha',
        ])->assertStatus(409);

        $this->as($creatorToken)->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(), 'kind' => 'receipt',
            'item_id' => $item, 'source_location_id' => null,
            'destination_location_id' => $location, 'quantity' => 1,
            'reason' => 'Estoque mudou após o plano',
        ])->assertCreated();
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'A sugestão mudou. Gere um novo plano antes de aprovar.');
        $this->assertDatabaseHas('stock_replenishment_plans', [
            'id' => $plan, 'status' => 'draft', 'version' => 1,
        ]);
    }

    public function test_manual_plan_validation_isolation_and_cancellation(): void
    {
        extract($this->fixture());
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-replenishment']);
        [, $foreignToken] = $this->token($other, 'owner');
        [$customer] = $this->token($tenant, 'customer');
        $this->createPlan($creatorToken, $customer->id, [])->assertUnprocessable();
        $this->as($creatorToken)->getJson('/api/v1/stock/operators?eligible_for_replenishment=1')
            ->assertOk()->assertJsonPath('total', 2);
        $this->createPlan($foreignToken, $reviewer->id, [])->assertUnprocessable();
        $this->createPlan($creatorToken, $reviewer->id, [$policy, $policy])->assertUnprocessable();
        $plan = $this->createPlan($creatorToken, $reviewer->id, [])
            ->assertCreated()->json('data.id');
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 1,
        ])->assertUnprocessable();
        $this->as($creatorToken)->patchJson("/api/v1/stock/replenishment-plans/{$plan}", [
            'expected_version' => 1,
            'items' => [
                ['item_id' => $item, 'location_id' => $location,
                    'quantity' => 1, 'safety_item' => false],
                ['item_id' => $item, 'location_id' => $location,
                    'quantity' => 2, 'safety_item' => true],
            ],
        ])->assertUnprocessable();
        $this->as($creatorToken)->patchJson("/api/v1/stock/replenishment-plans/{$plan}", [
            'expected_version' => 1,
            'items' => [['item_id' => $manualItem, 'location_id' => $location,
                'quantity' => 2, 'safety_item' => true]],
        ])->assertOk()->assertJsonPath('data.items.0.origin', 'manual');
        $this->as($foreignToken)->getJson("/api/v1/stock/replenishment-plans/{$plan}")
            ->assertNotFound();
        $this->as($foreignToken)->patchJson("/api/v1/stock/replenishment-plans/{$plan}", [
            'expected_version' => 2, 'notes' => 'Não autorizado',
        ])->assertNotFound();
        $this->as($creatorToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Cancelamento de teste',
        ])->assertForbidden();
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Cancelamento de teste',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Cancelamento de teste',
        ])->assertStatus(409);
        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $creator->id)
            ->update(['customer_scope' => 'assigned']);
        $this->as($creatorToken)->getJson('/api/v1/stock/replenishment-plans')->assertForbidden();
        $this->createPlan($creatorToken, $reviewer->id, [])->assertForbidden();
        [, $technicianToken] = $this->token($tenant, 'technician');
        $this->as($technicianToken)->getJson('/api/v1/stock/replenishment-plans')->assertForbidden();
    }

    public function test_author_cannot_approve_and_policy_change_invalidates_snapshot(): void
    {
        extract($this->fixture());
        $plan = $this->createPlan($reviewerToken, $reviewer->id, [$policy])
            ->assertCreated()->json('data.id');
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409)->assertJsonPath('message', 'A aprovação exige outro usuário autorizado.');

        $second = $this->createPlan($creatorToken, $reviewer->id, [$policy])
            ->assertCreated()->json('data.id');
        $this->as($creatorToken)->putJson("/api/v1/stock/reorder-policies/{$item}/{$location}", [
            'critical_quantity' => 1, 'safety_quantity' => 2,
            'minimum_quantity' => 3, 'target_quantity' => 10,
        ])->assertOk();
        $this->as($reviewerToken)->postJson("/api/v1/stock/replenishment-plans/{$second}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'A sugestão mudou. Gere um novo plano antes de aprovar.');
        $this->assertDatabaseHas('stock_replenishment_plans', [
            'id' => $second, 'status' => 'draft',
        ]);
    }
}
