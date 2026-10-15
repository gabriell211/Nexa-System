<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ResendWebhookProcessor;
use Illuminate\Console\Command;

final class ReconcileResendWebhooks extends Command
{
    protected $signature='nexa:mail:reconcile-resend-webhooks';
    protected $description='Reconcile previously authenticated early Resend events against persisted outbound UUIDs';

    public function handle(ResendWebhookProcessor $processor): int
    {
        if (config('nexa.collector_notifications.driver')!=='resend'
            || !config('nexa.collector_notifications.resend_webhooks_enabled')) {
            $this->info('Resend webhook reconciliation disabled.');
            return self::SUCCESS;
        }
        $limit=(int)config('nexa.collector_notifications.resend_reconcile_batch',100);
        $hours=(int)config('nexa.collector_notifications.resend_pending_hours',72);
        if ($limit<1 || $limit>500 || $hours<1 || $hours>168) {
            $this->error('Invalid provider event reconciliation bounds.');
            return self::FAILURE;
        }
        $result=$processor->reconcilePending($limit,$hours);
        $this->info(sprintf(
            'Processed %d correlated events: %d applied, %d stale; %d expired pending events.',
            $result['matched'],$result['applied'],$result['stale'],$result['expired']
        ));
        return self::SUCCESS;
    }
}
