<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CollectorIncidentNotifier;
use Illuminate\Console\Command;

final class SendCollectorIncidentNotifications extends Command
{
    protected $signature='nexa:alerts:notify-collectors';
    protected $description='Send opt-in, deduplicated email about Collector heartbeat incidents to verified tenant admins';

    public function handle(CollectorIncidentNotifier $notifier): int
    {
        if (!config('nexa.collector_notifications.enabled')) {
            $this->info('Collector mail notifications disabled. Set NEXA_COLLECTOR_NOTIFICATIONS_ENABLED=true only after configuring the mail transport and verifying recipients.');
            return self::SUCCESS;
        }
        if (!config('nexa.collector_health_alerts.enabled')) {
            $this->error('Collector heartbeat incident monitoring must be enabled before sending notifications.');
            return self::FAILURE;
        }
        $minutes=(int)config('nexa.collector_notifications.escalation_minutes',60);
        if ($minutes<15 || $minutes>10080) {
            $this->error('Invalid escalation threshold: 15..10080 minutes.');
            return self::FAILURE;
        }

        $perRun=(int)config('nexa.collector_notifications.max_per_run',50);
        $perTenant=(int)config('nexa.collector_notifications.max_per_tenant_hour',20);
        $global=(int)config('nexa.collector_notifications.max_global_hour',100);
        if ($perRun<1 || $perRun>500 || $perTenant<1 || $perTenant>1000
            || $global<1 || $global>10000 || $perTenant>$global) {
            $this->error('Invalid notification volume limits; per-run 1..500, per-tenant/hour 1..1000, global/hour 1..10000, tenant <= global.');
            return self::FAILURE;
        }
        $stats=$notifier->send($minutes,$perRun,$perTenant,$global);
        $this->info(sprintf(
            'Scanned %d open incidents; %d attempts, %d accepted by mail transport, %d failed, %d skipped/deduplicated, %d deferred due to volume limits.',
            $stats['incidents'],$stats['attempted'],$stats['accepted'],$stats['failed'],$stats['skipped'],$stats['deferred']
        ));
        return self::SUCCESS;
    }
}
