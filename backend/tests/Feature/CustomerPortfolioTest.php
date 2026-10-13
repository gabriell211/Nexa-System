<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerPortfolioTest extends TestCase
{
    use RefreshDatabase;

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => Str::uuid().'@example.test',
            'password' => 'secure-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return $user;
    }

    private function login(User $user, Tenant $tenant): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email,
            'password' => 'secure-test-password',
            'tenant' => $tenant->slug,
        ])->assertOk();
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $one = $tenant->customers()->create(['name' => 'Alpha']);
        $two = $tenant->customers()->create(['name' => 'Beta']);
        $unitA = $one->locations()->create(['tenant_id' => $tenant->id, 'name' => 'A HQ']);
        $unitB = $two->locations()->create(['tenant_id' => $tenant->id, 'name' => 'B HQ']);
        $printerA = $tenant->printers()->create([
            'customer_id' => $one->id, 'manufacturer' => 'Kyocera', 'model' => 'One',
        ]);
        $printerB = $tenant->printers()->create([
            'customer_id' => $two->id, 'manufacturer' => 'Ricoh', 'model' => 'Two',
        ]);
        $owner = $this->member($tenant, 'owner');
        $manager = $this->member($tenant, 'manager');

        return compact('tenant', 'one', 'two', 'unitA', 'unitB', 'printerA', 'printerB', 'owner', 'manager');
    }

    public function test_owner_assigns_manager_portfolio_and_it_limits_every_customer_endpoint(): void
    {
        extract($this->fixture());
        $this->login($owner, $tenant);
        $this->putJson("/api/v1/browser/staff/{$manager->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$one->id],
        ])->assertOk()->assertJsonPath('scope', 'assigned')
            ->assertJsonPath('customer_ids.0', $one->id);

        $this->getJson('/api/v1/browser/staff/'.$manager->id.'/portfolio')
            ->assertOk()->assertJsonPath('scope', 'assigned')
            ->assertJsonCount(1, 'customer_ids');
        $this->assertDatabaseHas('audit_entries', ['action' => 'staff.portfolio.updated', 'tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->login($manager, $tenant);

        $this->getJson('/api/v1/browser/customers')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $one->id);
        $this->getJson('/api/v1/browser/printers')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $printerA->id);
        $this->getJson('/api/v1/browser/dashboard')
            ->assertOk()->assertJsonPath('customers', 1)
            ->assertJsonPath('printers', 1)
            ->assertJsonPath('locations', 1);

        $this->getJson('/api/v1/browser/customers/'.$two->id)->assertNotFound();
        $this->getJson('/api/v1/browser/printers/'.$printerB->id)->assertNotFound();
        $this->getJson('/api/v1/browser/customers/'.$two->id.'/locations/'.$unitB->id)->assertNotFound();
        $this->postJson('/api/v1/browser/printers', [
            'customer_id' => $two->id, 'manufacturer' => 'Test', 'model' => 'Denied',
        ])->assertNotFound();
        $this->postJson('/api/v1/browser/customers', ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/v1/browser/staff')->assertForbidden();
        $this->getJson('/api/v1/browser/customers/'.$one->id.'/locations/'.$unitA->id)->assertOk();
    }

    public function test_explicit_empty_portfolio_means_no_clients_and_cannot_expose_dashboard(): void
    {
        extract($this->fixture());
        $this->login($owner, $tenant);
        $this->putJson("/api/v1/browser/staff/{$manager->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [],
        ])->assertOk()->assertJsonCount(0, 'customer_ids');
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->login($manager, $tenant);

        $this->getJson('/api/v1/browser/customers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/browser/printers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/browser/dashboard')->assertOk()
            ->assertJsonPath('customers', 0)->assertJsonPath('printers', 0);
        $this->getJson('/api/v1/browser/customers/'.$one->id)->assertNotFound();
    }

    public function test_portfolio_rejects_cross_tenant_customer_and_foreign_operator(): void
    {
        extract($this->fixture());
        $foreign = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $outsider = $this->member($foreign, 'manager');
        $foreignCustomer = $foreign->customers()->create(['name' => 'Foreign']);

        $this->login($owner, $tenant);
        $this->putJson("/api/v1/browser/staff/{$manager->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$foreignCustomer->id],
        ])->assertUnprocessable();
        $this->putJson("/api/v1/browser/staff/{$outsider->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$one->id],
        ])->assertNotFound();
        $this->assertDatabaseCount('customer_user_access', 0);
    }

    public function test_admin_portfolio_cannot_be_restricted_and_only_owner_admin_can_update(): void
    {
        extract($this->fixture());
        $this->login($owner, $tenant);
        $this->putJson("/api/v1/browser/staff/{$owner->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$one->id],
        ])->assertForbidden();
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->login($manager, $tenant);
        $this->putJson("/api/v1/browser/staff/{$owner->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$one->id],
        ])->assertForbidden();
    }

    public function test_default_portfolio_preserves_previous_full_customer_access(): void
    {
        extract($this->fixture());
        $this->login($manager, $tenant);
        $this->getJson('/api/v1/browser/customers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/browser/printers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_bearer_token_is_subject_to_the_same_portfolio_scope(): void
    {
        extract($this->fixture());
        $this->login($owner, $tenant);
        $this->putJson("/api/v1/browser/staff/{$manager->id}/portfolio", [
            'scope' => 'assigned', 'customer_ids' => [$one->id],
        ])->assertOk();
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();

        $bearer = $manager->createToken('qa', ['api', 'tenant:'.$tenant->id])->plainTextToken;
        $this->withToken($bearer)->getJson('/api/v1/customers')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha');
        $this->withToken($bearer)->getJson('/api/v1/printers/'.$printerB->id)->assertNotFound();
    }
}
