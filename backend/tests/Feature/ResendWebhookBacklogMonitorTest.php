<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CollectorAgent;
use App\Models\CollectorHealthIncident;
use App\Models\CollectorIncidentNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CollectorHeartbeatMonitor;
use App\Services\ResendWebhookBacklogMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResendWebhookBacklogMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN = 'f489ef10-c0b1-4d9a-8e12-235689ae0101';
    private const UNKNOWN = 'aa89ef10-c0b1-4d9a-8e12-235689ae0999';

    private function enable(): void
    {
        config()->set('nexa.collector_notifications.driver', 'resend');
        config()->set('nexa.collector_notifications.resend_webhooks_enabled', true);
        config()->set('nexa.collector_notifications.resend_monitor_enabled', true);
    }

    private function event(string $id, string $providerId, string $outcome, int $ageMinutes): void
    {
        DB::table('collector_mail_webhook_events')->insert([
            'event_hash' => hash('sha256', 'resend:'.$id),
            'kind' => 'email.delivered',
            'outcome' => $outcome,
            'provider_email_id' => $providerId,
            'received_at' => now('UTC')->subMinutes($ageMinutes),
        ]);
    }

    private function createKnownNotification(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Backlog tenant', 'slug' => 'backlog-tenant']);
        $customer = $tenant->customers()->create(['name' => 'Backlog customer']);
        $agent = CollectorAgent::query()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'collector_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'active' => true,
            'heartbeat_at' => now('UTC')->subHour(),
        ]);
        app(CollectorHeartbeatMonitor::class)->check(300);
        $incident = CollectorHealthIncident::query()
            ->where('collector_agent_id', $agent->id)->firstOrFail();
        $owner = User::query()->create([
            'name' => 'Owner', 'email' => 'backlog-owner@example.test',
            'password' => 'test-only-password', 'active' => true,
        ]);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);
        CollectorIncidentNotification::query()->create([
            'tenant_id' => $tenant->id,
            'collector_health_incident_id' => $incident->id,
            'recipient_user_id' => $owner->id,
            'stage' => 'initial',
            'status' => 'accepted',
            'attempted_at' => now('UTC'),
            'provider_email_id' => self::KNOWN,
        ]);
    }

    public function test_disabled_monitor_does_not_query_or_change_events(): void
    {
        $this->artisan('nexa:mail:check-resend-backlog', [
            '--json' => true, '--fail-on-alert' => true,
        ])->expectsOutput('{"status":"disabled"}')->assertSuccessful();
        $this->assertDatabaseCount('collector_mail_webhook_events', 0);
    }

    public function test_empty_queue_is_healthy_and_strict_check_passes(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $snapshot = app(ResendWebhookBacklogMonitor::class)->snapshot();
        $this->assertSame('healthy', $snapshot['status']);
        $this->assertSame(0, $snapshot['pending_total']);
        $this->assertNull($snapshot['oldest_pending_at']);
        $this->artisan('nexa:mail:check-resend-backlog', [
            '--fail-on-alert' => true,
        ])->assertSuccessful();
    }

    public function test_unmatched_old_webhook_warns_without_exposing_provider_uuid(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->event('unknown', self::UNKNOWN, 'pending', 16);
        $this->event('applied', self::KNOWN, 'applied', 20);

        $before = DB::table('collector_mail_webhook_events')->get()->toArray();
        $snapshot = app(ResendWebhookBacklogMonitor::class)->snapshot();
        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame(1, $snapshot['pending_unknown']);
        $this->assertSame(1, $snapshot['overdue_unknown']);
        $this->assertSame(1, $snapshot['applied_total']);
        $this->assertStringNotContainsString(self::UNKNOWN, json_encode($snapshot));
        $this->assertStringNotContainsString('event_hash', json_encode($snapshot));
        $this->artisan('nexa:mail:check-resend-backlog', [
            '--fail-on-alert' => true,
        ])->assertFailed();
        $this->artisan('nexa:mail:check-resend-backlog')->assertSuccessful();
        $this->assertEquals($before, DB::table('collector_mail_webhook_events')->get()->toArray());
    }

    public function test_known_recoverable_event_overdue_is_critical_not_unknown(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        config()->set('nexa.collector_health_alerts.enabled', true);
        $this->createKnownNotification();
        $this->event('known', self::KNOWN, 'pending', 3);
        $this->event('unknown', self::UNKNOWN, 'pending', 1);

        $snapshot = app(ResendWebhookBacklogMonitor::class)->snapshot();
        $this->assertSame('critical', $snapshot['status']);
        $this->assertSame(2, $snapshot['pending_total']);
        $this->assertSame(1, $snapshot['pending_known']);
        $this->assertSame(1, $snapshot['pending_unknown']);
        $this->assertSame(1, $snapshot['overdue_known']);
        $this->assertSame(0, $snapshot['overdue_unknown']);
    }

    public function test_critical_capacity_and_invalid_configuration_fail_closed(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->event('one', self::UNKNOWN, 'pending', 0);
        $this->event('two', self::KNOWN, 'pending', 0);
        config()->set('nexa.collector_notifications.resend_critical_pending_count', 2);
        $this->assertSame('critical', app(ResendWebhookBacklogMonitor::class)
            ->snapshot(15, 2, 2)['status']);
        $this->artisan('nexa:mail:check-resend-backlog', [
            '--fail-on-alert' => true,
        ])->assertFailed();

        config()->set('nexa.collector_notifications.resend_warn_unknown_minutes', 0);
        $this->artisan('nexa:mail:check-resend-backlog')->assertFailed();
        config()->set('nexa.collector_notifications.resend_warn_unknown_minutes', 15);
        config()->set('nexa.collector_notifications.driver', 'laravel');
        $this->artisan('nexa:mail:check-resend-backlog')->assertFailed();
        $this->assertDatabaseCount('collector_mail_webhook_events', 2);
    }

    public function test_expired_rows_are_historical_metrics_not_active_alarms(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->event('expired', self::UNKNOWN, 'expired', 1000);
        $snapshot = app(ResendWebhookBacklogMonitor::class)->snapshot();
        $this->assertSame('healthy', $snapshot['status']);
        $this->assertSame(1, $snapshot['expired_total']);
        $this->assertSame(0, $snapshot['pending_total']);
    }
}
