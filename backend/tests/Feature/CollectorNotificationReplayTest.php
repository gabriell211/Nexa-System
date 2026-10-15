<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CollectorHealthIncidentMail;
use App\Models\CollectorAgent;
use App\Models\CollectorHealthIncident;
use App\Models\CollectorIncidentNotification;
use App\Models\CollectorNotificationReplay;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CollectorHeartbeatMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorNotificationReplayTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name='replay',string $status='failed'): array
    {
        $tenant=Tenant::query()->create(['name'=>'Tenant '.$name,'slug'=>'replay-'.$name]);
        $customer=$tenant->customers()->create(['name'=>'Client '.$name]);
        $agent=CollectorAgent::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,
            'collector_id'=>(string)Str::uuid(),
            'token_hash'=>hash('sha256',bin2hex(random_bytes(32))),
            'active'=>true,'heartbeat_at'=>now('UTC')->subHour(),
        ]);
        app(CollectorHeartbeatMonitor::class)->check(300);
        $incident=CollectorHealthIncident::query()
            ->where('collector_agent_id',$agent->id)->firstOrFail();
        $owner=User::query()->create([
            'name'=>'Collector owner','email'=>Str::uuid().'@example.test',
            'password'=>'test-password','active'=>true,
        ]);
        $owner->forceFill(['email_verified_at'=>now('UTC')])->save();
        $tenant->users()->attach($owner->id,['role'=>'owner']);
        $attempt=CollectorIncidentNotification::query()->create([
            'tenant_id'=>$tenant->id,
            'collector_health_incident_id'=>$incident->id,
            'recipient_user_id'=>$owner->id,
            'stage'=>'initial','status'=>$status,
            'attempted_at'=>now('UTC')->subHours(2),
            'error_category'=>$status==='failed'?'transport_failure':null,
        ]);
        return [$tenant,$agent,$incident,$owner,$attempt];
    }

    private function enable(): void
    {
        config()->set('nexa.collector_health_alerts.enabled',true);
        config()->set('nexa.collector_notifications.enabled',true);
    }

    private function replay(int $id,string $ref='CASE-123',bool $confirmed=true): \Illuminate\Testing\PendingCommand
    {
        $args=[
            'notification_id'=>(string)$id,
            '--review-reference'=>$ref,
        ];
        if ($confirmed) $args['--confirm-not-accepted']=true;
        return $this->artisan('nexa:alerts:replay-collector-mail',$args);
    }

    public function test_verified_failure_can_be_replayed_once_and_original_is_preserved(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        [$tenant,$agent,$incident,$owner,$original]=$this->fixture();
        $this->enable();
        $this->replay($original->id,confirmed:false)->assertFailed();
        $this->assertDatabaseCount('collector_notification_replays',0);
        $this->replay($original->id,'not valid spaces')->assertFailed();
        $this->assertDatabaseCount('collector_notification_replays',0);
        $this->replay($original->id)->assertSuccessful();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $replay=CollectorNotificationReplay::query()->firstOrFail();
        $this->assertSame('accepted',$replay->status);
        $this->assertSame('CASE-123',$replay->review_reference);
        $this->assertSame($original->id,$replay->original_notification_id);
        $this->assertNotNull($replay->accepted_at);
        $this->assertSame('failed',$original->fresh()->status);
        $this->replay($original->id)->assertFailed();
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
        $this->assertDatabaseCount('collector_notification_replays',1);
        $this->assertDatabaseHas('audit_entries',[
            'action'=>'collector.notice.replay_authorized',
            'origin'=>'manual-backend-provider-review',
            'entity_type'=>'CollectorNotificationReplay',
        ]);
        $this->assertDatabaseHas('audit_entries',[
            'action'=>'collector.notice.replay_accepted',
            'origin'=>'manual-backend-provider-review',
        ]);
    }

    public function test_accepted_bounced_complaint_and_ambiguous_originals_never_replay(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        foreach (['accepted','returned','complaint','attempting','skipped'] as $status) {
            [$tenant,$agent,$incident,$owner,$original]=$this->fixture('blocked-'.$status,$status);
            $this->enable();
            $this->replay($original->id)->assertFailed();
        }
        $this->assertDatabaseCount('collector_notification_replays',0);
        Mail::assertNothingSent();
    }

    public function test_revoked_owner_or_recovered_incident_cannot_be_replayed(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        [$tenant,$agent,$incident,$owner,$original]=$this->fixture('revoked');
        $this->enable();
        $owner->update(['active'=>false]);
        $this->replay($original->id)->assertFailed();
        $owner->update(['active'=>true]);
        $incident->update([
            'status'=>'resolved','resolved_at'=>now('UTC'),
            'resolution_reason'=>'heartbeat_resumed',
        ]);
        $this->replay($original->id)->assertFailed();
        $this->assertDatabaseCount('collector_notification_replays',0);
        Mail::assertNothingSent();
    }

    public function test_replay_respects_original_reservations_and_shared_hourly_limits(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        [$tenant,$agent,$incident,$owner,$original]=$this->fixture('budget');
        $this->enable();
        config()->set('nexa.collector_notifications.max_per_tenant_hour',1);
        config()->set('nexa.collector_notifications.max_global_hour',1);

        $first=CollectorIncidentNotification::query()->create([
            'tenant_id'=>$tenant->id,
            'collector_health_incident_id'=>$incident->id,
            'recipient_user_id'=>$owner->id,
            'stage'=>'escalation','status'=>'failed',
            'attempted_at'=>now('UTC')->subMinutes(2),
        ]);
        $this->replay($original->id)->assertFailed();
        $this->assertDatabaseCount('collector_notification_replays',0);
        $first->update(['attempted_at'=>now('UTC')->subHours(2)]);
        $this->replay($original->id)->assertSuccessful();
        $this->assertDatabaseCount('collector_notification_replays',1);

        // Even with both original reservations old, the replay itself still
        // consumes a slot and blocks the scheduled sender.
        $other=User::query()->create([
            'name'=>'Other admin','email'=>Str::uuid().'@example.test',
            'password'=>'test-password','active'=>true,
        ]);
        $other->forceFill(['email_verified_at'=>now('UTC')])->save();
        $tenant->users()->attach($other->id,['role'=>'admin']);
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $this->assertDatabaseCount('collector_incident_notifications',2);
        Mail::assertSent(CollectorHealthIncidentMail::class,1);
    }

    public function test_transport_exception_is_sanitized_and_is_not_replayed_again(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        [$tenant,$agent,$incident,$owner,$original]=$this->fixture('smtp-failure');
        $this->enable();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('secret-SMTP-password'));
        $this->replay($original->id)->assertFailed();
        $replay=CollectorNotificationReplay::query()->firstOrFail();
        $this->assertSame('failed',$replay->status);
        $this->assertSame('transport_failure',$replay->error_category);
        $this->assertStringNotContainsString('secret-SMTP-password',json_encode($replay->toArray()));
        $this->replay($original->id)->assertFailed();
        $this->assertDatabaseCount('collector_notification_replays',1);
        $this->assertDatabaseHas('audit_entries',['action'=>'collector.notice.replay_failed']);
    }

    public function test_feature_opt_in_is_required_for_manual_replay(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        [$tenant,$agent,$incident,$owner,$original]=$this->fixture('opt-in');
        config()->set('nexa.collector_health_alerts.enabled',true);
        config()->set('nexa.collector_notifications.enabled',false);
        $this->replay($original->id)->assertFailed();
        config()->set('nexa.collector_notifications.enabled',true);
        config()->set('nexa.collector_health_alerts.enabled',false);
        $this->replay($original->id)->assertFailed();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('collector_notification_replays',0);
    }
}
