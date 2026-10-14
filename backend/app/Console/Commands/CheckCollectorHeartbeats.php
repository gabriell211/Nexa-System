<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CollectorHeartbeatMonitor;
use Illuminate\Console\Command;

final class CheckCollectorHeartbeats extends Command
{
    protected $signature = 'nexa:alerts:check-collectors';
    protected $description = 'Report authenticated Collector heartbeat silence without claiming devices are offline';

    public function handle(CollectorHeartbeatMonitor $monitor): int
    {
        if (!config('nexa.collector_health_alerts.enabled')) {
            $this->info('Disabled: enable NEXA_COLLECTOR_HEALTH_ALERTS_ENABLED after reviewing thresholds and schedule.');
            return self::SUCCESS;
        }
        $seconds=(int)config('nexa.collector_health_alerts.silence_seconds',300);
        if ($seconds<60 || $seconds>86400) {
            $this->error('Invalid collector silence threshold. Allowed: 60..86400 seconds.');
            return self::FAILURE;
        }
        $result=$monitor->check($seconds);
        $this->info(sprintf(
            'Checked %d stale agents; opened %d, deduplicated %d, recovered %d, closed %d.',
            $result['checked'],$result['opened'],$result['deduplicated'],
            $result['recovered'],$result['closed']
        ));
        return self::SUCCESS;
    }
}
