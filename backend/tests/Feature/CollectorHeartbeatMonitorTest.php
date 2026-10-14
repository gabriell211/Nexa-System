<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CollectorAgent;
use App\Models\CollectorHealthIncident;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CollectorHeartbeatMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorHeartbeatMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $slug, ?CarbonImmutable $heartbeat=null): CollectorAgent
    {
        $tenant=Tenant::query()->create(['name'=>'Provider '.$slug,'slug'=>$slug]);
        $customer=$tenant->customers()->create(['name'=>'Customer '.$slug]);
        return CollectorAgent::query()->create([
            'tenant_id'=>$tenant->id,
            'customer_id'=>$customer->id,
            'collector_id'=>(string)Str::uuid(),
            'token_hash'=>hash('sha256',bin2hex(random_bytes(32))),
            'active'=>true,
            'heartbeat_at'=>$heartbeat,
        ]);
    }

    private function startMonitoring(int $seconds=300): void
    {
        config()->set('nexa.collector_health_alerts.enabled',true);
        config()->set('nexa.collector_health_alerts.silence_seconds',$seconds);
    }

    public function test_only_agents_with_previous_heartbeat_become_late_and_are_deduplicated(): void
    {
        $now=CarbonImmutable::parse('2026-10-14T12:00:00Z');
        $this->travelTo($now);
        $unprovisioned=$this->agent('never');
        $onTime=$this->agent('fresh',$now->subSeconds(299));
        $late=$this->agent('late',$now->subMinutes(15));
        $this->startMonitoring();
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',1);
        $this->assertDatabaseHas('collector_health_incidents',[
            'collector_agent_id'=>$late->id,'code'=>'COLLECTOR_HEARTBEAT_LATE',
            'tenant_id'=>$late->tenant_id,'customer_id'=>$late->customer_id,
            'status'=>'active',
        ]);
        $this->assertDatabaseMissing('collector_health_incidents',['collector_agent_id'=>$unprovisioned->id]);
        $this->assertDatabaseMissing('collector_health_incidents',['collector_agent_id'=>$onTime->id]);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',1);
        $this->assertDatabaseHas('audit_entries',[
            'actor_user_id'=>null,'action'=>'collector.heartbeat_late.opened',
            'origin'=>'scheduled-heartbeat-check','entity_type'=>'CollectorHealthIncident',
        ]);
    }

    public function test_authenticated_heartbeat_recovers_incident_only_after_real_new_receipt(): void
    {
        $now=CarbonImmutable::parse('2026-10-14T12:00:00Z');
        $this->travelTo($now);
        $agent=$this->agent('resume',$now->subMinutes(20));
        $this->startMonitoring();
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $incident=CollectorHealthIncident::query()->firstOrFail();
        $this->assertSame('active',$incident->status);
        // Upload time alone does not establish independent heartbeat recovery.
        $agent->update(['last_seen_at'=>CarbonImmutable::now('UTC')]);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertSame('active',$incident->fresh()->status);
        $agent->update(['heartbeat_at'=>CarbonImmutable::now('UTC')->addSecond()]);
        $this->travel(2)->seconds();
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertSame('resolved',$incident->fresh()->status);
        $this->assertSame('heartbeat_resumed',$incident->fresh()->resolution_reason);
        $this->assertDatabaseHas('audit_entries',[
            'actor_user_id'=>null,'action'=>'collector.heartbeat_late.resolved',
        ]);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',1);
        $this->travel(6)->minutes();
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',2);
        $this->assertSame(1,CollectorHealthIncident::query()->where('status','active')->count());
    }

    public function test_revoked_or_inactive_organization_closes_without_claiming_recovery(): void
    {
        $now=CarbonImmutable::parse('2026-10-14T12:00:00Z');
        $this->travelTo($now);
        $a=$this->agent('revoke',$now->subHour());
        $b=$this->agent('disabled-customer',$now->subHour());
        $c=$this->agent('disabled-tenant',$now->subHour());
        $this->startMonitoring();
        app(CollectorHeartbeatMonitor::class)->check(300);
        $this->assertDatabaseCount('collector_health_incidents',3);
        $a->update(['active'=>false]);
        $b->customer()->update(['active'=>false]);
        DB::table('tenants')->where('id',$c->tenant_id)->update(['active'=>false]);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertSame(0,CollectorHealthIncident::query()->where('status','active')->count());
        $this->assertSame(3,CollectorHealthIncident::query()->where('status','closed')
            ->where('resolution_reason','deactivated')->count());
        $this->assertDatabaseHas('audit_entries',['action'=>'collector.heartbeat_late.closed']);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',3);
    }

    public function test_never_online_deactivated_and_no_historical_heartbeat_are_not_alerts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $new=$this->agent('new-one');
        $inactive=$this->agent('revoked-before',CarbonImmutable::now('UTC')->subHours(4));
        $inactive->update(['active'=>false]);
        $this->startMonitoring();
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',0);
        $this->assertNull($new->fresh()->heartbeat_at);
    }

    public function test_operator_opt_in_and_invalid_threshold_are_fail_closed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $this->agent('unconfigured',CarbonImmutable::now('UTC')->subHour());
        config()->set('nexa.collector_health_alerts.enabled',false);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',0);
        $this->startMonitoring(0);
        $this->artisan('nexa:alerts:check-collectors')->assertFailed();
        $this->assertDatabaseCount('collector_health_incidents',0);
        $this->startMonitoring(300);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_health_incidents',1);
    }

    public function test_incident_listing_and_live_banner_respect_tenant_and_technician_portfolio(): void
    {
        $now=CarbonImmutable::parse('2026-10-14T12:00:00Z');
        $this->travelTo($now);
        $agent=$this->agent('local',$now->subHour());
        $unassigned=$this->agent('other-tenant',$now->subHour());
        $customer=$agent->customer;
        $owner=User::query()->create([
            'name'=>'Owner','email'=>'collector-admin@example.test',
            'password'=>'test-secure-password',
        ]);
        $tech=User::query()->create([
            'name'=>'Technician','email'=>'collector-technician@example.test',
            'password'=>'test-secure-password',
        ]);
        $tenant=Tenant::query()->findOrFail($agent->tenant_id);
        $tenant->users()->attach($owner->id,['role'=>'owner']);
        $tenant->users()->attach($tech->id,['role'=>'technician','customer_scope'=>'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$tech->id,'customer_id'=>$customer->id,
        ]);
        $ownerToken=$owner->createToken('api',['api','tenant:'.$tenant->id])->plainTextToken;
        $techToken=$tech->createToken('api',['api','tenant:'.$tenant->id])->plainTextToken;
        $this->startMonitoring();
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-health-incidents')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.collector_agent_id',$agent->id)
            ->assertJsonPath('data.0.code','COLLECTOR_HEARTBEAT_LATE')
            ->assertJsonMissingPath('data.0.token_hash');
        Auth::forgetGuards();
        $this->withToken($techToken)->getJson('/api/v1/collector-health-incidents')
            ->assertOk()->assertJsonCount(1,'data');
        Auth::forgetGuards();
        $this->withToken($techToken)->getJson('/api/v1/collector-agents')
            ->assertOk()->assertJsonPath('data.0.communication_incident.status','active');
        Auth::forgetGuards();
        $this->withToken($techToken)->getJson('/api/v1/collector-health-incidents?collector_agent_id='.$unassigned->id)
            ->assertOk()->assertJsonCount(0,'data');
        Auth::forgetGuards();
        $this->getJson('/api/v1/collector-health-incidents')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($ownerToken)->getJson('/api/v1/collector-health-incidents?per_page=1001')
            ->assertUnprocessable();
    }

    public function test_archived_incidents_remain_visible_in_history_with_resolution_reason(): void
    {
        $now=CarbonImmutable::parse('2026-10-14T12:00:00Z');
        $this->travelTo($now);
        $agent=$this->agent('history',$now->subHour());
        $this->startMonitoring();
        app(CollectorHeartbeatMonitor::class)->check(300);
        $agent->update(['heartbeat_at'=>$now->addMinute()]);
        $this->travel(2)->minutes();
        app(CollectorHeartbeatMonitor::class)->check(300);
        $alert=CollectorHealthIncident::query()->firstOrFail();
        $this->assertSame('resolved',$alert->status);
        $this->assertSame('heartbeat_resumed',$alert->resolution_reason);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame(0,CollectorHealthIncident::query()->where('status','active')->count());
    }
}
