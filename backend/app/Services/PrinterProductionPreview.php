<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Printer;
use App\Support\UnsignedDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conservative advisory preview of monotonic changes between observations.
 * Never use these values as invoice quantities or imply page production.
 */
final class PrinterProductionPreview
{
    private const CHANNELS = ['total', 'mono', 'color'];
    private const MAX_SAMPLES = 20000;

    /** @return array<string, mixed> */
    public function calculate(Printer $printer, string $startDate, string $endDate): array
    {
        $from = $startDate.' 00:00:00';
        $until = CarbonImmutable::parse($endDate.' 00:00:00', 'UTC')
            ->addDay()->format('Y-m-d H:i:s');

        $query = DB::table('printer_readings')
            ->where('tenant_id', $printer->tenant_id)
            ->where('printer_id', $printer->id)
            ->where('collected_at', '>=', $from)
            ->where('collected_at', '<', $until);

        $sampleCount = (int) (clone $query)->count();
        if ($sampleCount > self::MAX_SAMPLES) {
            throw ValidationException::withMessages([
                'start_date' => 'Intervalo com mais de 20.000 amostras; divida a consulta.',
            ]);
        }

        $channels = [];
        foreach (self::CHANNELS as $key) {
            $channels[$key] = [
                'observed_delta' => '0',
                'paired_intervals' => 0,
                'missing_intervals' => 0,
                'decrease_intervals' => 0,
            ];
        }

        $distinctTimes = 0;
        $repeatedTimestamps = 0;
        $conflictingTimestamps = 0;
        $previous = null;
        $group = null;

        $consume = static function (array $point) use (&$previous, &$distinctTimes, &$conflictingTimestamps, &$channels): void {
            $distinctTimes++;
            if ($point['conflict']) {
                $conflictingTimestamps++;
                // A contradictory timestamp invalidates both adjacent deltas.
                $previous = null;
                return;
            }

            if ($previous !== null) {
                foreach (self::CHANNELS as $key) {
                    $left = $previous['values'][$key];
                    $right = $point['values'][$key];

                    if ($left === null || $right === null) {
                        $channels[$key]['missing_intervals']++;
                        continue;
                    }
                    if (UnsignedDecimal::compare($right, $left) < 0) {
                        // Reset, rollover or corrupted sample: never add negative
                        // deltas and never mistake the new value for output.
                        $channels[$key]['decrease_intervals']++;
                        continue;
                    }

                    $channels[$key]['observed_delta'] = UnsignedDecimal::add(
                        $channels[$key]['observed_delta'],
                        UnsignedDecimal::subtract($right, $left),
                    );
                    $channels[$key]['paired_intervals']++;
                }
            }
            $previous = $point;
        };

        foreach ((clone $query)->select(['collected_at', 'id', 'meter_total', 'meter_mono', 'meter_color'])
            ->orderBy('collected_at')->orderBy('id')->cursor() as $reading) {
            $values = [];
            foreach (self::CHANNELS as $key) {
                $raw = $reading->{'meter_'.$key};
                $values[$key] = $raw === null ? null : UnsignedDecimal::normalize((string) $raw);
            }

            $stamp = (string) $reading->collected_at;
            if ($group !== null && $stamp === $group['time']) {
                $repeatedTimestamps++;
                if ($values !== $group['values']) {
                    $group['conflict'] = true;
                }
                // Retain the final ID as canonical, while remembering conflicts
                // against ALL samples in the group, not just the prior one.
                $group['values'] = $values;
                continue;
            }
            if ($group !== null) {
                $consume($group);
            }
            $group = ['time' => $stamp, 'values' => $values, 'conflict' => false];
        }
        if ($group !== null) {
            $consume($group);
        }

        $hasPairs = false;
        $hasGaps = $conflictingTimestamps > 0;
        foreach ($channels as &$channel) {
            $hasPairs = $hasPairs || $channel['paired_intervals'] > 0;
            $hasGaps = $hasGaps
                || $channel['decrease_intervals'] > 0
                || $channel['missing_intervals'] > 0;
            if ($channel['paired_intervals'] === 0) {
                // There is no meaningful delta for this channel.
                $channel['observed_delta'] = null;
            }
        }
        unset($channel);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'scope' => 'observed_pairs_only',
            'billing_ready' => false,
            'status' => !$hasPairs ? 'insufficient_data'
                : ($hasGaps ? 'review_required' : 'observed'),
            'sample_count' => $sampleCount,
            'distinct_timestamps' => $distinctTimes,
            'repeated_timestamps' => $repeatedTimestamps,
            'conflicting_timestamps' => $conflictingTimestamps,
            'channels' => $channels,
        ];
    }
}
