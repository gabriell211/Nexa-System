<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CollectorAgent;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $tenant=Tenant::query()->create(['name'=>'Collector provider','slug'=>'heartbeat-company']);
        $customer=$tenant->customers()->create(['name'=>'Active customer']);
        $other=$tenant->customers()->create(['name'=>'Other customer']);
        $secret=bin2hex(random_bytes(32));
        $agent=CollectorAgent::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,
            'collector_id'=>(string)Str::uuid(),'token_hash'=>hash('sha256',$secret),
            'active'=>true,
        ]);
        $owner=User::query()->create([
            'name'=>'Owner','email'=>(string)Str::uuid().'@example.test',
            'password'=>'secure-test-password',
        ]);
        $tenant->users()->attach($owner->id,['role'=>'owner']);
        $ownerToken=$owner->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken;
        $tech=User::query()->create([
            'name'=>'Scoped technician','email'=>(string)Str::uuid().'@example.test',
            'password'=>'secure-test-password',
        ]);
        $tenant->users()->attach($tech->id,['role'=>'technician','customer_scope'=>'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$tech->id,'customer_id'=>$customer->id,
        ]);
        $techToken=$tech->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken;
        return compact('tenant','customer','other','secret','agent','ownerToken','techToken');
    }

    private function heartbeat(string $secret,string $collectorId,array $extra=[]): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($secret)->postJson('/api/v1/collector/heartbeat',[
            'collector_id'=>$collectorId,...$extra,
        ]);
    }

    public function test_independent_heartbeat_uses_server_clock_and_never_writes_meter_or_upload_time(): void
    {
        extract($this->fixtures());
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $this->heartbeat($secret,$agent->collector_id,[
            'reported_at'=>'2040-01-01T00:00:00Z','meter_total'=>999999,
        ])->assertOk()->assertJsonPath('acknowledged',true)
            ->assertJsonPath('collector_id',$agent->collector_id);
        $agent->refresh();
        $this->assertSame('2026-10-14T12:00:00+00:00',$agent->heartbeat_at->toIso8601String());
        $this->assertNull($agent->last_seen_at);
        $this->assertDatabaseCount('printer_readings',0);

        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.heartbeat_state','recent')
            ->assertJsonPath('data.0.last_upload_at',null);
    }

    public function test_heartbeat_does_not_require_an_online_printer_or_any_meter_sample(): void
    {
        extract($this->fixtures());
        $this->heartbeat($secret,$agent->collector_id)->assertOk();
        $this->assertDatabaseCount('printer_readings',0);
    }

    public function test_ingest_updates_upload_clock_without_fabricating_independent_heartbeat(): void
    {
        extract($this->fixtures());
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'MA5500ifx',
        ]);
        Auth::forgetGuards();
        $this->withToken($secret)->postJson('/api/v1/collector/ingest',[
            'collector_id'=>$agent->collector_id,
            'samples'=>[[
                'sample_id'=>(string)Str::uuid(),
                'printer_id'=>$printer->id,
                'collected_at'=>now('UTC')->toIso8601String(),
                'meter_total'=>42,'meter_mono'=>null,'meter_color'=>null,
            ]],
        ])->assertOk();
        $agent->refresh();
        $this->assertNotNull($agent->last_seen_at);
        $this->assertNull($agent->heartbeat_at);
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonPath('data.0.heartbeat_state','no_heartbeat')
            ->assertJsonPath('data.0.last_upload_at',$agent->last_seen_at->toIso8601String());
    }

    public function test_wrong_token_wrong_uuid_and_missing_bearer_cannot_update_heartbeat(): void
    {
        extract($this->fixtures());
        $this->heartbeat(str_repeat('a',64),$agent->collector_id)->assertUnauthorized();
        $this->heartbeat($secret,(string)Str::uuid())->assertUnauthorized();
        $this->heartbeat($secret,'not-a-uuid')->assertUnprocessable();
        Auth::forgetGuards();
        $this->postJson('/api/v1/collector/heartbeat',[
            'collector_id'=>$agent->collector_id,
        ])->assertUnauthorized();
        $this->assertNull($agent->fresh()->heartbeat_at);
    }

    public function test_revoked_or_inactive_organizations_cannot_heartbeat(): void
    {
        extract($this->fixtures());
        $agent->update(['active'=>false]);
        $this->heartbeat($secret,$agent->collector_id)->assertUnauthorized();
        $agent->update(['active'=>true]);
        $customer->update(['active'=>false]);
        $this->heartbeat($secret,$agent->collector_id)->assertForbidden();
        $customer->update(['active'=>true]);
        $tenant->update(['active'=>false]);
        $this->heartbeat($secret,$agent->collector_id)->assertForbidden();
        $this->assertNull($agent->fresh()->heartbeat_at);
    }

    public function test_status_never_recent_late_and_revoked_is_not_physical_offline(): void
    {
        extract($this->fixtures());
        config()->set('nexa.collector_heartbeat.max_silence_seconds',180);
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonPath('data.0.heartbeat_state','no_heartbeat');
        $this->heartbeat($secret,$agent->collector_id)->assertOk();
        $this->travel(181)->seconds();
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonPath('data.0.heartbeat_state','late');
        $this->heartbeat($secret,$agent->collector_id)->assertOk();
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonPath('data.0.heartbeat_state','recent');
        $agent->update(['active'=>false]);
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonPath('data.0.heartbeat_state','revoked');
    }

    public function test_tenant_and_scoped_portfolio_cannot_read_foreign_agent_identity(): void
    {
        extract($this->fixtures());
        $unassigned=CollectorAgent::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$other->id,
            'collector_id'=>(string)Str::uuid(),
            'token_hash'=>hash('sha256',bin2hex(random_bytes(32))),
            'active'=>true,
        ]);
        Auth::forgetGuards();
        $this->withToken($techToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.collector_id',$agent->collector_id);
        $otherTenant=Tenant::query()->create(['name'=>'Another','slug'=>'another-collector-company']);
        $otherUser=User::query()->create([
            'name'=>'Other owner','email'=>(string)Str::uuid().'@example.test',
            'password'=>'test-password',
        ]);
        $otherTenant->users()->attach($otherUser->id,['role'=>'owner']);
        $otherToken=$otherUser->createToken('test',['api','tenant:'.$otherTenant->id])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonCount(0,'data');
        $this->assertDatabaseHas('collector_agents',['id'=>$unassigned->id,'tenant_id'=>$tenant->id]);
    }

    public function test_list_is_paginated_and_never_exposes_token_hash(): void
    {
        extract($this->fixtures());
        Auth::forgetGuards();
        $response=$this->withToken($ownerToken)->getJson('/api/v1/collector-agents?per_page=1')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonMissingPath('data.0.token_hash');
        $this->assertStringNotContainsString($agent->token_hash,$response->getContent());
        $this->getJson('/api/v1/collector-agents?per_page=1001')->assertUnprocessable();
    }
}
