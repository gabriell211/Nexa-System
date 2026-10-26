<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CollectorAgent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_identifies_only_its_active_customer_without_mutating_heartbeat(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Cliente A']);
        $other = $tenant->customers()->create(['name' => 'Cliente B']);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        $agent = CollectorAgent::query()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'collector_id' => $id, 'token_hash' => hash('sha256', $token), 'active' => true,
        ]);

        $this->withToken($token)->postJson('/api/v1/collector/identity', ['collector_id' => $id])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('collector_id', $id)
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('customer_name', 'Cliente A')
            ->assertJsonMissing(['customer_id' => $other->id]);
        $this->assertNull($agent->fresh()->heartbeat_at);

        $this->withToken($token)->postJson('/api/v1/collector/identity', ['collector_id' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->withToken(str_repeat('0', 64))->postJson('/api/v1/collector/identity', ['collector_id' => $id])
            ->assertUnauthorized();

        $agent->update(['active' => false]);
        $this->withToken($token)->postJson('/api/v1/collector/identity', ['collector_id' => $id])
            ->assertUnauthorized();
    }

    public function test_inactive_customer_cannot_be_identified(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Cliente A', 'active' => false]);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        CollectorAgent::query()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'collector_id' => $id, 'token_hash' => hash('sha256', $token), 'active' => true,
        ]);
        $this->withToken($token)->postJson('/api/v1/collector/identity', ['collector_id' => $id])
            ->assertForbidden();
    }
}
