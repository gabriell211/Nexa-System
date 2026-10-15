<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CollectorHealthIncidentMail;
use App\Models\CollectorAgent;
use App\Models\CollectorHealthIncident;
use App\Models\CollectorIncidentNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CollectorHeartbeatMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorIncidentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function incident(string $slug): array
    {
        $tenant=Tenant::query()->create(['name'=>'Provider '.$slug,'slug'=>$slug]);
        $customer=$tenant->customers()->create(['name'=>'Customer '.$slug]);
        $agent=CollectorAgent::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,
            'collector_id'=>(string)Str::uuid(),
            'token_hash'=>hash('sha256',bin2hex(random_bytes(32))),
            'active'=>true,'heartbeat_at'=>CarbonImmutable::now('UTC')->subHour(),
        ]);
        app(CollectorHeartbeatMonitor::class)->check(300);
        $incident=CollectorHealthIncident::query()->where('collector_agent_id',$agent->id)->firstOrFail();
        return [$tenant,$agent,$incident];
    }

    private function member(Tenant $tenant,string $role,bool $verified=true,bool $active=true): User
    {
        $user=User::query()->create([
            'name'=>'Operator '.$role,
            'email'=>(string)Str::uuid().'@example.test',
            'password'=>'test-only-password',
            'active'=>$active,
        ]);
        if ($verified) {
            $user->forceFill(['email_verified_at'=>now('UTC')])->save();
        }
        $tenant->users()->attach($user->id,['role'=>$role]);
        return $user;
    }

    private function enable(): void
    {
        config()->set('nexa.collector_health_alerts.enabled',true);
        config()->set('nexa.collector_notifications.enabled',true);
        config()->set('nexa.collector_notifications.escalation_minutes',60);
    }

    public function test_verified_owners_and_admins_receive_one_initial_notice_even_after_repeat_scheduler_runs(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('dedup-notification');
        $owner=$this->member($tenant,'owner');
        $admin=$this->member($tenant,'admin');
        $this->member($tenant,'technician');
        $this->member($tenant,'admin',verified:false);
        $this->member($tenant,'owner',active:false);
        $this->enable();

        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,2);
        $this->assertSame(2,CollectorIncidentNotification::query()->count());
        $this->assertSame(2,CollectorIncidentNotification::query()
            ->where('stage','initial')->where('status','accepted')->count());
        $this->assertDatabaseHas('collector_incident_notifications',[
            'recipient_user_id'=>$owner->id,'status'=>'accepted',
            'collector_health_incident_id'=>$incident->id,
        ]);
        $this->assertDatabaseHas('collector_incident_notifications',[
            'recipient_user_id'=>$admin->id,'stage'=>'initial',
        ]);
        $this->assertDatabaseHas('audit_entries',[
            'action'=>'collector.notice.accepted','origin'=>'scheduled-collector-notification',
            'entity_type'=>'CollectorIncidentNotification',
        ]);

        $this->assertDatabaseCount('printer_alerts',0);
    }

    public function test_after_escalation_window_only_verified_owner_receives_second_stage(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('escalation-timing');
        $owner=$this->member($tenant,'owner');
        $admin=$this->member($tenant,'admin');
        $this->enable();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();

        $this->travel(59)->minutes();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,2);

        $this->travel(2)->minutes();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,3);
        $this->assertDatabaseHas('collector_incident_notifications',[
            'recipient_user_id'=>$owner->id,'stage'=>'escalation','status'=>'accepted',
        ]);
        $this->assertDatabaseMissing('collector_incident_notifications',[
            'recipient_user_id'=>$admin->id,'stage'=>'escalation',
        ]);
    }

    public function test_resolved_and_deactivated_incidents_cannot_emit_new_stage_or_other_tenant_mail(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('resolved-no-email');
        $this->member($tenant,'owner');
        [$otherTenant,$otherAgent,$otherIncident]=$this->incident('other-tenant-alert');
        $otherOwner=$this->member($otherTenant,'owner');
        $this->enable();

        $agent->update(['heartbeat_at'=>now('UTC')->addSecond()]);
        $this->travel(2)->seconds();
        app(CollectorHeartbeatMonitor::class)->check(300);
        $this->assertSame('resolved',$incident->fresh()->status);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $this->assertDatabaseHas('collector_incident_notifications',[
            'tenant_id'=>$otherTenant->id,'recipient_user_id'=>$otherOwner->id,
        ]);
        $this->assertDatabaseMissing('collector_incident_notifications',[
            'tenant_id'=>$tenant->id,
        ]);

        $otherAgent->update(['active'=>false]);
        $this->travel(62)->minutes();
        // Keep the already-recovered independent tenant online. Otherwise
        // 62 minutes of silence rightfully creates a new incident there.
        $agent->update(['heartbeat_at'=>now('UTC')]);
        $this->artisan('nexa:alerts:check-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $this->assertSame('closed',$otherIncident->fresh()->status);
    }

    public function test_mail_send_failure_is_audited_without_raw_transport_error_or_automatic_retry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('transport-error');
        $this->member($tenant,'owner');
        $this->enable();
        Mail::shouldReceive('to')->once()
            ->andThrow(new \RuntimeException('SMTP_PASSWORD: unsafe-secret'));
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $record=CollectorIncidentNotification::query()->firstOrFail();
        $this->assertSame('failed',$record->status);
        $this->assertSame('transport_failure',$record->error_category);
        $this->assertNotNull($record->attempted_at);
        $this->assertNull($record->accepted_at);
        $this->assertStringNotContainsString('unsafe-secret',json_encode($record->toArray()));
        $this->assertDatabaseHas('audit_entries',['action'=>'collector.notice.failed']);
    }

    public function test_notifications_are_disabled_by_default_and_require_incident_monitoring(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('explicit-optin');
        $this->member($tenant,'owner');
        config()->set('nexa.collector_notifications.enabled',false);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertNothingSent();

        config()->set('nexa.collector_notifications.enabled',true);
        config()->set('nexa.collector_health_alerts.enabled',false);
        $this->artisan('nexa:alerts:notify-collectors')->assertFailed();
        Mail::assertNothingSent();

        $this->enable();
        config()->set('nexa.collector_notifications.escalation_minutes',0);
        $this->artisan('nexa:alerts:notify-collectors')->assertFailed();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('collector_incident_notifications',0);
    }

    public function test_per_run_budget_defers_without_reserving_or_dropping_pending_notices(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('run-cap');
        $this->member($tenant,'owner');
        $this->member($tenant,'admin');
        $this->enable();
        config()->set('nexa.collector_notifications.max_per_run',1);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $this->assertDatabaseCount('collector_incident_notifications',1);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,2);
        $this->assertDatabaseCount('collector_incident_notifications',2);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,2);
    }

    public function test_per_tenant_rolling_hour_budget_defers_and_recovers_after_window(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('tenant-cap');
        $this->member($tenant,'owner');
        $this->member($tenant,'admin');
        $this->enable();
        config()->set('nexa.collector_notifications.max_per_tenant_hour',1);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $this->assertDatabaseCount('collector_incident_notifications',1);

        $this->travel(61)->minutes();
        // Deliberately keep the same incident active for the reminder stage.
        // No new reservation was made for the deferred second recipient.
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,2);
        $this->assertDatabaseCount('collector_incident_notifications',2);
    }

    public function test_global_hour_budget_is_shared_by_tenants_not_reset_per_process(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenantA,$agentA]=$this->incident('global-cap-a');
        $this->member($tenantA,'owner');
        [$tenantB,$agentB]=$this->incident('global-cap-b');
        $this->member($tenantB,'owner');
        $this->enable();
        config()->set('nexa.collector_notifications.max_per_tenant_hour',1);
        config()->set('nexa.collector_notifications.max_global_hour',1);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $this->assertDatabaseCount('collector_incident_notifications',1);
        $this->assertSame(1,CollectorIncidentNotification::query()
            ->where('tenant_id',$tenantA->id)->count());
        $this->assertSame(0,CollectorIncidentNotification::query()
            ->where('tenant_id',$tenantB->id)->count());

        $this->travel(61)->minutes();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,2);
        $this->assertDatabaseCount('collector_incident_notifications',2);
    }

    public function test_invalid_mail_budgets_fail_closed_before_any_send(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant]=$this->incident('invalid-budget');
        $this->member($tenant,'owner');
        $this->enable();
        foreach ([
            ['max_per_run',0],['max_per_run',501],
            ['max_per_tenant_hour',0],['max_per_tenant_hour',1001],
            ['max_global_hour',0],['max_global_hour',10001],
        ] as [$name,$value]) {
            config()->set('nexa.collector_notifications.'.$name,$value);
            $this->artisan('nexa:alerts:notify-collectors')->assertFailed();
            config()->set('nexa.collector_notifications.'.$name,
                match ($name) {
                    'max_per_run'=>50,'max_per_tenant_hour'=>20,'max_global_hour'=>100,
                });
        }
        config()->set('nexa.collector_notifications.max_per_tenant_hour',21);
        config()->set('nexa.collector_notifications.max_global_hour',20);
        $this->artisan('nexa:alerts:notify-collectors')->assertFailed();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('collector_incident_notifications',0);
    }

    public function test_failed_attempt_consumes_budget_and_is_never_retried_automatically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant]=$this->incident('error-cap');
        $this->member($tenant,'owner');
        $this->member($tenant,'admin');
        $this->enable();
        config()->set('nexa.collector_notifications.max_per_tenant_hour',1);
        Mail::shouldReceive('to')->once()->andThrow(
            new \RuntimeException('sensitive-SMTP-error')
        );
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->assertSame(1,CollectorIncidentNotification::query()->where('status','failed')->count());
        $this->assertDatabaseCount('collector_incident_notifications',1);
        $this->assertStringNotContainsString('sensitive-SMTP-error',
            json_encode(CollectorIncidentNotification::query()->firstOrFail()->toArray()));
    }

    public function test_api_exposes_aggregate_accepted_counts_but_not_recipient_addresses(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        [$tenant,$agent,$incident]=$this->incident('count-visibility');
        $owner=$this->member($tenant,'owner');
        $this->enable();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $token=$owner->createToken('tests',['api','tenant:'.$tenant->id])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/collector-health-incidents')
            ->assertOk()->assertJsonPath('data.0.email_accepted_count',1)
            ->assertJsonPath('data.0.email_unconfirmed_count',0)
            ->assertJsonMissingPath('data.0.recipient_user_id')
            ->assertJsonMissingPath('data.0.recipient_email');
    }
}
