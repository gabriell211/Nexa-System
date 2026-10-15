<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CollectorNotificationReplayService;
use Illuminate\Console\Command;

/**
 * Privileged, one-shot replay. Never scheduled or exposed over HTTP.
 * --confirm-not-accepted means the operator independently reviewed provider
 * evidence; the application cannot prove the original mail was undelivered.
 */
final class ReplayCollectorMail extends Command
{
    protected $signature='nexa:alerts:replay-collector-mail
        {notification_id : Original failed delivery attempt database ID}
        {--review-reference= : External ticket or provider evidence reference, 4..64 safe characters}
        {--confirm-not-accepted : Operator reviewed evidence and accepts duplicate-delivery risk}';
    protected $description='Authorize a single supervised resend of a failed Collector notification, after provider review';

    public function handle(CollectorNotificationReplayService $service): int
    {
        if (!config('nexa.collector_notifications.enabled')
            || !config('nexa.collector_health_alerts.enabled')) {
            $this->error('Both incident monitoring and notifications must be enabled for manual replay.');
            return self::FAILURE;
        }
        $id=filter_var($this->argument('notification_id'),FILTER_VALIDATE_INT,[
            'options'=>['min_range'=>1],
        ]);
        $reference=$this->option('review-reference');
        if (!$id || !is_string($reference)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,63}$/D',$reference)
            || $this->option('confirm-not-accepted')!==true) {
            $this->error('A valid ID, --review-reference=CASE-ID and --confirm-not-accepted are required.');
            return self::FAILURE;
        }
        $perTenant=(int)config('nexa.collector_notifications.max_per_tenant_hour',20);
        $global=(int)config('nexa.collector_notifications.max_global_hour',100);
        $result=$service->replay((int)$id,$reference,$perTenant,$global);
        if ($result==='accepted') {
            $this->info('One reviewed replay accepted by mail transport (delivery to inbox unverified).');
            return self::SUCCESS;
        }
        if ($result==='deferred') {
            $this->warn('Mail budget exhausted. No attempt reserved. Reassess evidence before manually invoking again.');
        } else {
            $this->error('Replay not permitted or not transport-accepted; check audit status without exposing SMTP diagnostics.');
        }
        return self::FAILURE;
    }
}
