<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\CollectorAgent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerCollectorCredentialTest extends TestCase
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

    private function bearer(User $user, Tenant $tenant): string
    {
        return $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken;
    }

    public function test_owner_issues_copyable_one_time_key_without_persisting_secret_or_exposing_it_again(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Printer customer']);
        $owner = $this->member($tenant, 'owner');

        $endpoint = '/api/v1/customers/'.$customer->id.'/collector-credentials';
        $first = $this->withToken($this->bearer($owner, $tenant))
            ->postJson($endpoint)->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private');
        $id = $first->json('collector_id');
        $token = $first->json('collector_token');

        $this->assertSame($customer->id, $first->json('customer_id'));
        $this->assertTrue(Str::isUuid($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertDatabaseHas('collector_agents', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'collector_id' => $id,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);

        $agent = CollectorAgent::query()->where('collector_id', $id)->firstOrFail();
        $this->assertArrayNotHasKey('token_hash', $agent->toArray());
        $this->assertSame('collector.credential.issued', AuditEntry::query()->firstOrFail()->action);
        $audit = json_encode(AuditEntry::query()->firstOrFail()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString(hash('sha256', $token), $audit);

        $this->getJson('/api/v1/customers')->assertOk()
            ->assertJsonMissingPath('data.0.collector_token');
        $this->getJson('/api/v1/collector-agents')->assertOk()
            ->assertJsonMissingPath('data.0.collector_token')
            ->assertJsonMissingPath('data.0.token_hash');

        // A second installation gets different credentials; the first remains valid.
        $second = $this->postJson($endpoint)->assertCreated();
        $this->assertNotSame($id, $second->json('collector_id'));
        $this->assertNotSame($token, $second->json('collector_token'));
        $this->assertDatabaseCount('collector_agents', 2);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/collector/heartbeat', [
            'collector_id' => $id,
        ])->assertOk()->assertJsonPath('acknowledged', true);
    }

    public function test_only_owner_and_admin_can_issue_credentials(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Customer']);
        $endpoint = '/api/v1/customers/'.$customer->id.'/collector-credentials';

        foreach (['manager', 'supervisor', 'technician', 'warehouse', 'customer'] as $role) {
            Auth::forgetGuards();
            $actor = $this->member($tenant, $role);
            $this->withToken($this->bearer($actor, $tenant))->postJson($endpoint)->assertForbidden();
        }
        $this->assertDatabaseCount('collector_agents', 0);

        Auth::forgetGuards();
        $admin = $this->member($tenant, 'admin');
        $this->withToken($this->bearer($admin, $tenant))->postJson($endpoint)->assertCreated();
    }

    public function test_other_tenant_and_inactive_customer_cannot_receive_keys(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $foreignTenant = Tenant::query()->create(['name' => 'Outside', 'slug' => 'outside']);
        $foreign = $foreignTenant->customers()->create(['name' => 'Foreign']);
        $inactive = $tenant->customers()->create(['name' => 'Inactive', 'active' => false]);
        $owner = $this->member($tenant, 'owner');
        $this->withToken($this->bearer($owner, $tenant));

        $this->postJson('/api/v1/customers/'.$foreign->id.'/collector-credentials')->assertNotFound();
        $this->postJson('/api/v1/customers/'.$inactive->id.'/collector-credentials')->assertUnprocessable();
        $this->postJson('/api/v1/customers/999999/collector-credentials')->assertNotFound();
        $this->assertDatabaseCount('collector_agents', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_browser_session_can_issue_key_but_logged_out_session_cannot(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Customer']);
        $owner = $this->member($tenant, 'owner');
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $owner->email,
            'password' => 'secure-test-password',
            'tenant' => 'provider',
        ])->assertOk();

        $endpoint = '/api/v1/browser/customers/'.$customer->id.'/collector-credentials';
        $this->postJson($endpoint)->assertCreated()->assertJsonStructure([
            'customer_id', 'collector_id', 'collector_token',
        ]);
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->postJson($endpoint)->assertUnauthorized();
        $this->assertDatabaseCount('collector_agents', 1);
    }
}
