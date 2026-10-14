<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelemetryGapMonitor;
use Illuminate\Console\Command;

final class CheckTelemetryGaps extends Command
{
    protected $signature = 'nexa:alerts:check-telemetry';
    protected $description = 'Evaluate missing collector readings without claiming printer connectivity';

    public function handle(TelemetryGapMonitor $monitor): int
    {
        if (!config('nexa.telemetry_gap.enabled')) {
            $this->info('Disabled: set NEXA_TELEMETRY_GAP_ENABLED=true after reviewing the sampling interval.');
            return self::SUCCESS;
        }

        $threshold=(int)config('nexa.telemetry_gap.threshold_minutes',60);
        $recovery=(int)config('nexa.telemetry_gap.recovery_minutes',10);
        if ($threshold<15 || $threshold>10080 || $recovery<1 || $recovery>1440 ||
            $recovery>=$threshold) {
            $this->error('Invalid telemetry thresholds. Required: 15..10080 gap minutes and 1..1440 recovery minutes, recovery below gap.');
            return self::FAILURE;
        }

        $counts=$monitor->check($threshold,$recovery);
        $this->info(sprintf(
            'Examined %d candidates, opened %d new gaps, resolved %d, deduplicated %d.',
            $counts['checked'],$counts['opened'],$counts['resolved'],$counts['deduplicated']
        ));
        return self::SUCCESS;
    }
}
