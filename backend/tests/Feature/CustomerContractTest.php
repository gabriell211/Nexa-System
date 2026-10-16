<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerContractTest extends TestCase
{
    use RefreshDatabase;

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => Str::uuid().'@example.test',
            'password' => 'contract-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return $user;
    }

    private function login(User $user, Tenant $tenant): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email,
            'password' => 'contract-test-password',
            'tenant' => $tenant->slug,
        ])->assertOk();
    }

    private function payload(int $customerId, string $number): array
    {
        return [
            'customer_id' => $customerId,
            'document_number' => $number,
            'title' => 'Locação e impressão',
            'provider_unit_name' => 'Unidade São Paulo',
            'billing_address' => 'Rua de Teste, 100',
            'billing_timezone' => 'America/Sao_Paulo',
            'effective_on' => '2026-01-01',
            'billing_starts_on' => '2026-01-31',
            'ends_on' => '2026-12-31',
            'is_default' => true,
        ];
    }

    public function test_month_end_cycle_and_default_replacement_are_persisted_and_audited(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Alpha']);
        $this->login($this->member($tenant, 'owner'), $tenant);

        $first = $this->postJson('/api/v1/browser/contracts', $this->payload($customer->id, 'A-001'))
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $this->getJson("/api/v1/browser/contracts/$first/cycle?as_of=2026-02-28")
            ->assertOk()->assertJsonPath('cycle.starts_on', '2026-02-28')
            ->assertJsonPath('cycle.ends_on', '2026-03-30');
        $this->getJson("/api/v1/browser/contracts/$first/cycle?as_of=2026-03-30")
            ->assertOk()->assertJsonPath('cycle.starts_on', '2026-02-28');
        $this->getJson("/api/v1/browser/contracts/$first/cycle?as_of=2026-03-31")
            ->assertOk()->assertJsonPath('cycle.starts_on', '2026-03-31');
        $this->getJson("/api/v1/browser/contracts/$first/cycle?as_of=2026-12-31")
            ->assertOk()->assertJsonPath('cycle.ends_on', '2026-12-31')
            ->assertJsonPath('cycle.scheduled_ends_on', '2027-01-30');

        $second = $this->postJson('/api/v1/browser/contracts', $this->payload($customer->id, 'A-002'))
            ->assertCreated()->json('data.id');
        $this->getJson("/api/v1/browser/contracts/$first")
            ->assertOk()->assertJsonPath('data.is_default', false)->assertJsonPath('data.version', 2);
        $this->getJson("/api/v1/browser/contracts/$second")
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertDatabaseCount('customer_contracts', 2);
        $this->assertDatabaseHas('audit_entries', ['action' => 'customer_contract.default_replaced']);
    }

    public function test_version_dates_and_document_conflicts_reject_invalid_changes(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Alpha']);
        $this->login($this->member($tenant, 'owner'), $tenant);
        $id = $this->postJson('/api/v1/browser/contracts', $this->payload($customer->id, 'A-001'))
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/browser/contracts/$id", [
            'expected_version' => 0, 'title' => 'Stale',
        ])->assertUnprocessable();
        $this->patchJson("/api/v1/browser/contracts/$id", [
            'expected_version' => 1, 'billing_starts_on' => '2025-12-01',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/browser/contracts', [
            ...$this->payload($customer->id, 'A-001'), 'is_default' => false,
        ])->assertUnprocessable();
        $this->patchJson("/api/v1/browser/contracts/$id", [
            'expected_version' => 1, 'active' => false,
        ])->assertOk()->assertJsonPath('data.is_default', false)->assertJsonPath('data.version', 2);
        $this->patchJson("/api/v1/browser/contracts/$id", [
            'expected_version' => 1, 'title' => 'Stale after update',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->getJson("/api/v1/browser/contracts/$id/cycle?as_of=2027-01-01")
            ->assertOk()->assertJsonPath('cycle', null);
    }

    public function test_portfolio_and_tenant_boundaries_hide_contracts(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        $alpha = $tenant->customers()->create(['name' => 'Alpha']);
        $beta = $tenant->customers()->create(['name' => 'Beta']);
        $foreign = $other->customers()->create(['name' => 'Foreign']);
        $owner = $this->member($tenant, 'owner');
        $manager = $this->member($tenant, 'manager');
        $this->login($owner, $tenant);
        $a = $this->postJson('/api/v1/browser/contracts', $this->payload($alpha->id, 'A-001'))
            ->assertCreated()->json('data.id');
        $b = $this->postJson('/api/v1/browser/contracts', $this->payload($beta->id, 'B-001'))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/browser/contracts', $this->payload($foreign->id, 'X-001'))
            ->assertNotFound();
        $this->putJson("/api/v1/browser/staff/{$manager->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$alpha->id],
        ])->assertOk();
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->login($manager, $tenant);
        $this->getJson('/api/v1/browser/contracts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $a);
        $this->getJson("/api/v1/browser/contracts/$b")->assertNotFound();
        $this->getJson("/api/v1/browser/contracts/$b/cycle?as_of=2026-03-01")->assertNotFound();
        $this->patchJson("/api/v1/browser/contracts/$b", [
            'expected_version' => 1, 'title' => 'Denied',
        ])->assertNotFound();
    }

    public function test_customer_with_active_contract_cannot_be_deactivated(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Alpha']);
        $this->login($this->member($tenant, 'owner'), $tenant);
        $id = $this->postJson('/api/v1/browser/contracts', $this->payload($customer->id, 'A-001'))
            ->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/browser/customers/{$customer->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->patchJson("/api/v1/browser/contracts/$id", [
            'expected_version' => 1, 'active' => false,
        ])->assertOk();
        $this->deleteJson("/api/v1/browser/customers/{$customer->id}")->assertNoContent();
    }
}
