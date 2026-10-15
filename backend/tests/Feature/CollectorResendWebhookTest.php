<?php
declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL_ID='f489ef10-c0b1-4d9a-8e12-235689ae0101';
    private const REPLAY_ID='c689ef10-c0b1-4d9a-8e12-235689ae0102';

    private function enabled(): void
    {
        config()->set('nexa.collector_health_alerts.enabled',true);
        config()->set('nexa.collector_notifications.enabled',true);
        config()->set('nexa.collector_notifications.driver','resend');
        config()->set('nexa.collector_notifications.resend_api_key','re_test_key_private');
        config()->set('nexa.collector_notifications.resend_webhooks_enabled',true);
        config()->set('nexa.collector_notifications.resend_webhook_secret',
            'whsec_'.base64_encode(str_repeat('X',32)));
        config()->set('mail.from.address','notifications@example.test');
        config()->set('mail.from.name','Nexa');
    }

    private function fixture(): array
    {
        $tenant=Tenant::query()->create(['name'=>'Webhook Tenant','slug'=>'webhook-nexa']);
        $customer=$tenant->customers()->create(['name'=>'Print Fleet']);
        $agent=CollectorAgent::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,
            'collector_id'=>(string)Str::uuid(),
            'token_hash'=>hash('sha256',bin2hex(random_bytes(32))),
            'active'=>true,'heartbeat_at'=>now('UTC')->subHour(),
        ]);
        app(CollectorHeartbeatMonitor::class)->check(300);
        $incident=CollectorHealthIncident::query()->where('collector_agent_id',$agent->id)->firstOrFail();
        $owner=User::query()->create([
            'name'=>'Owner','email'=>'provider-owner@example.test','password'=>'test-password',
            'active'=>true,
        ]);
        $owner->forceFill(['email_verified_at'=>now('UTC')])->save();
        $tenant->users()->attach($owner->id,['role'=>'owner']);
        return [$tenant,$agent,$incident,$owner];
    }

    private function event(string $type,string $emailId=self::EMAIL_ID,array $details=[]): string
    {
        return json_encode([
            'type'=>$type,'created_at'=>'2026-10-15T11:00:00Z',
            'data'=>array_merge(['email_id'=>$emailId,'to'=>['private@example.test']],$details),
        ],JSON_THROW_ON_ERROR);
    }

    private function webhook(string $raw,string $id='msg_event_A',?string $signature=null,?int $timestamp=null)
    {
        $timestamp??=time();
        $signature??='v1,'.base64_encode(hash_hmac('sha256',
            $id.'.'.$timestamp.'.'.$raw,str_repeat('X',32),true));
        return $this->call('POST','/api/v1/webhooks/resend',[],[],[],[
            'CONTENT_TYPE'=>'application/json',
            'HTTP_SVIX_ID'=>$id,
            'HTTP_SVIX_TIMESTAMP'=>(string)$timestamp,
            'HTTP_SVIX_SIGNATURE'=>$signature,
        ],$raw);
    }

    private function sentOriginal(): CollectorIncidentNotification
    {
        Http::fake(['https://api.resend.com/emails'=>Http::response(['id'=>self::EMAIL_ID],200)]);
        Mail::fake();
        $this->artisan('nexa:alerts:notify-collectors')->assertSuccessful();
        $notice=CollectorIncidentNotification::query()->firstOrFail();
        $this->assertSame('accepted',$notice->status);
        $this->assertSame(self::EMAIL_ID,$notice->provider_email_id);
        Mail::assertNothingSent();
        Http::assertSent(fn($request)=>
            $request->url()==='https://api.resend.com/emails'
            && $request->hasHeader('Idempotency-Key','nexa-collector-notice-'.$notice->id)
            && count($request['to'])===1
        );
        return $notice;
    }

    public function test_verified_delivered_bounce_and_complaint_progress_without_exposing_payload(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->enabled();
        [$tenant,$agent,$incident,$owner]=$this->fixture();
        $notice=$this->sentOriginal();

        $this->webhook($this->event('email.delivered'))->assertOk()
            ->assertJsonPath('result','applied');
        $this->assertSame('delivered',$notice->fresh()->status);
        $this->assertNotNull($notice->fresh()->provider_delivered_at);
        $this->webhook($this->event('email.bounced',self::EMAIL_ID,[
            'bounce'=>['type'=>'Permanent','message'=>'private mail subject/recipient'],
        ]),'msg_event_B')->assertOk()->assertJsonPath('result','applied');
        $this->assertSame('returned',$notice->fresh()->status);
        $this->assertSame('hard_bounce',$notice->fresh()->error_category);

        // Out-of-order delivery cannot reverse a terminal bounce.
        $this->webhook($this->event('email.delivered'),'msg_event_C')
            ->assertOk()->assertJsonPath('result','stale');
        $this->assertSame('returned',$notice->fresh()->status);
        $this->webhook($this->event('email.complained'),'msg_event_D')
            ->assertOk()->assertJsonPath('result','applied');
        $this->assertSame('complaint',$notice->fresh()->status);
        $this->webhook($this->event('email.bounced'),'msg_event_E')
            ->assertOk()->assertJsonPath('result','stale');
        $this->assertSame('complaint',$notice->fresh()->status);
        $this->assertDatabaseHas('audit_entries',[
            'action'=>'collector.notice.provider_complaint',
            'origin'=>'verified-resend-webhook','tenant_id'=>$tenant->id,
        ]);
        $this->assertSame(5,DB::table('collector_mail_webhook_events')->count());
        $this->assertStringNotContainsString('private@example.test',
            json_encode(DB::table('collector_mail_webhook_events')->first()));
        $this->assertStringNotContainsString('private mail subject',
            json_encode(DB::table('audit_entries')->get()->toArray()));
    }

    public function test_signature_body_timestamp_and_header_tampering_never_mutates_an_incident(): void
    {
        $this->enabled();
        [$tenant,$agent,$incident,$owner]=$this->fixture();
        $notice=$this->sentOriginal();
        $raw=$this->event('email.complained');
        $this->webhook($raw,'bad_event','v1,invalid')->assertUnauthorized();
        // No legitimate provider event should run in a signature-tampering
        // test: only requests with false signatures or stale times are sent.
        $signedBody=$raw;
        $signature='v1,'.base64_encode(hash_hmac('sha256',
            'tamper.'.time().'.'.$signedBody,str_repeat('X',32),true));
        $this->webhook($raw.' ' ,'tamper',$signature)->assertUnauthorized();
        $this->webhook($raw,'expired',null,time()-500)->assertUnauthorized();
        $this->webhook($raw,'future',null,time()+500)->assertUnauthorized();
        $this->webhook($raw,'invalid',null,123)->assertUnauthorized();
        $this->assertSame('accepted',$notice->fresh()->status);
    }

    public function test_svix_redelivery_is_idempotent_even_with_changed_payload(): void
    {
        $this->enabled();
        [$tenant,$agent,$incident,$owner]=$this->fixture();
        $notice=$this->sentOriginal();
        $this->webhook($this->event('email.bounced'),'one_event')
            ->assertOk()->assertJsonPath('result','applied');
        $this->webhook($this->event('email.complained'),'one_event')
            ->assertOk()->assertJsonPath('result','duplicate');
        $this->assertSame('returned',$notice->fresh()->status);
        $this->assertSame(1,DB::table('collector_mail_webhook_events')->count());
    }

    public function test_unknown_provider_email_never_correlates_by_recipient_or_subject(): void
    {
        $this->enabled();
        [$tenant,$agent,$incident,$owner]=$this->fixture();
        $notice=$this->sentOriginal();
        $this->webhook($this->event(
            'email.bounced','aa89ef10-c0b1-4d9a-8e12-235689ae0999'
        ),'outside_event')->assertOk()->assertJsonPath('result','unmatched');
        $this->assertSame('accepted',$notice->fresh()->status);
        $this->assertSame(0,DB::table('audit_entries')
            ->where('action','collector.notice.provider_returned')->count());
    }

    public function test_disabled_or_invalid_secret_fails_closed_before_processing(): void
    {
        $this->enabled();
        $this->fixture();
        $body=$this->event('email.bounced');
        config()->set('nexa.collector_notifications.resend_webhooks_enabled',false);
        $this->webhook($body)->assertNotFound();
        config()->set('nexa.collector_notifications.resend_webhooks_enabled',true);
        config()->set('nexa.collector_notifications.resend_webhook_secret','whsec_invalid');
        $this->webhook($body)->assertUnauthorized();
        $this->assertDatabaseCount('collector_mail_webhook_events',0);
    }

    public function test_replay_provider_email_id_is_separate_and_reconciled_by_signed_webhook(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->enabled();
        [$tenant,$agent,$incident,$owner]=$this->fixture();
        $original=CollectorIncidentNotification::query()->create([
            'tenant_id'=>$tenant->id,
            'collector_health_incident_id'=>$incident->id,
            'recipient_user_id'=>$owner->id,
            'stage'=>'initial',
            'status'=>'failed',
            'attempted_at'=>now('UTC')->subHours(2),
        ]);
        Http::fake(['https://api.resend.com/emails'=>Http::response(['id'=>self::REPLAY_ID],200)]);
        $this->artisan('nexa:alerts:replay-collector-mail',[
            'notification_id'=>(string)$original->id,
            '--review-reference'=>'CASE-123',
            '--confirm-not-accepted'=>true,
        ])->assertSuccessful();
        $replay=CollectorNotificationReplay::query()->firstOrFail();
        $this->assertSame(self::REPLAY_ID,$replay->provider_email_id);
        $this->webhook($this->event('email.delivered',self::REPLAY_ID),'replay_event')
            ->assertOk()->assertJsonPath('result','applied');
        $this->assertSame('delivered',$replay->fresh()->status);
        $this->assertSame('failed',$original->fresh()->status);
    }
}
