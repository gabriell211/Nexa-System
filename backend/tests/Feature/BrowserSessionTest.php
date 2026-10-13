<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(Tenant $tenant, string $role = 'owner'): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => Str::uuid().'@example.test',
            'password' => 'long-secure-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return $user;
    }

    private function login(User $user, string $slug): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email,
            'password' => 'long-secure-test-password',
            'tenant' => $slug,
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('token');
    }

    public function test_public_csrf_handshake_and_browser_login_creates_session(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $user = $this->userFor($tenant);

        $csrf = $this->getJson('/api/v1/browser/auth/csrf')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('csrf_token');
        $this->assertIsString($csrf);
        $this->assertNotEmpty($csrf);

        $this->login($user, 'provider');
        $this->getJson('/api/v1/browser/auth/me')->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('role', 'owner');
    }

    public function test_session_auth_scopes_backoffice_and_blocks_other_tenant(): void
    {
        $one = Tenant::query()->create(['name' => 'One', 'slug' => 'one']);
        $two = Tenant::query()->create(['name' => 'Two', 'slug' => 'two']);
        $owner = $this->userFor($one);
        $owned = $one->customers()->create(['name' => 'Visible']);
        $foreign = $two->customers()->create(['name' => 'Private']);
        $this->login($owner, 'one');

        $this->getJson('/api/v1/browser/customers')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id);
        $this->getJson('/api/v1/browser/customers/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/browser/customers', ['name' => 'New'])->assertCreated();
        $this->assertDatabaseHas('customers', ['name' => 'New', 'tenant_id' => $one->id]);
    }

    public function test_logout_invalidates_cookie_session_and_denies_further_requests(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $this->login($this->userFor($tenant), 'provider');

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();

        $this->getJson('/api/v1/browser/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/browser/customers')->assertUnauthorized();
    }

    public function test_login_rejects_wrong_password_or_unregistered_tenant(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $user = $this->userFor($tenant);

        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email, 'password' => 'not-the-password', 'tenant' => 'provider',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email, 'password' => 'long-secure-test-password', 'tenant' => 'other',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/browser/auth/me')->assertUnauthorized();
    }

    public function test_membership_revocation_takes_effect_on_next_web_request(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $user = $this->userFor($tenant);
        $this->login($user, 'provider');
        $tenant->users()->detach($user->id);
        $this->getJson('/api/v1/browser/customers')->assertForbidden();
    }

    public function test_web_customer_role_cannot_access_internal_dashboard(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $this->login($this->userFor($tenant, 'customer'), 'provider');
        $this->getJson('/api/v1/browser/dashboard')->assertForbidden();
    }

    public function test_legacy_token_issuance_is_off_by_default(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $user = $this->userFor($tenant);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'long-secure-test-password', 'tenant' => 'provider',
        ])->assertStatus(410);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_route_does_not_implicitly_accept_web_session(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $this->login($this->userFor($tenant), 'provider');
        $this->getJson('/api/v1/browser/dashboard')->assertOk();

        // API token guard is isolated from the web middleware and cookie session.
        Auth::forgetGuards();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
