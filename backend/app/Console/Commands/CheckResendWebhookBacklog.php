<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ResendWebhookBacklogMonitor;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class CheckResendWebhookBacklog extends Command
{
    protected $signature = 'nexa:mail:check-resend-backlog
        {--json : Output only safe, aggregate machine-readable metrics}
        {--fail-on-alert : Exit nonzero for warning or critical backlog}';

    protected $description = 'Read-only operational health of signed Resend webhook reconciliation';

    public function handle(ResendWebhookBacklogMonitor $monitor): int
    {
        if (!config('nexa.collector_notifications.resend_monitor_enabled')) {
            $this->line($this->option('json')
                ? '{"status":"disabled"}'
                : 'Resend backlog monitoring disabled.');
            return self::SUCCESS;
        }

        if (config('nexa.collector_notifications.driver') !== 'resend'
            || !config('nexa.collector_notifications.resend_webhooks_enabled')) {
            $this->error('Resend backlog monitoring requires the signed Resend webhook integration.');
            return self::FAILURE;
        }

        try {
            $snapshot = $monitor->snapshot(
                (int) config('nexa.collector_notifications.resend_warn_unknown_minutes', 15),
                (int) config('nexa.collector_notifications.resend_critical_known_minutes', 2),
                (int) config('nexa.collector_notifications.resend_critical_pending_count', 100),
            );
        } catch (InvalidArgumentException) {
            $this->error('Invalid Resend backlog monitoring thresholds.');
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'Resend backlog %s: %d pending (%d known, %d unknown); %d known overdue, %d unknown overdue; %d expired historically.',
                $snapshot['status'],
                $snapshot['pending_total'],
                $snapshot['pending_known'],
                $snapshot['pending_unknown'],
                $snapshot['overdue_known'],
                $snapshot['overdue_unknown'],
                $snapshot['expired_total'],
            ));
        }

        return $this->option('fail-on-alert') && $snapshot['status'] !== 'healthy'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
