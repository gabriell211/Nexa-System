<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/** Calendar-month cycles keep the original billing day, clamping only short months. */
final class ContractCycle
{
    public static function at(string $billingStartsOn, string $asOf, ?string $endsOn = null): ?array
    {
        if ($asOf < $billingStartsOn || ($endsOn !== null && $asOf > $endsOn)) {
            return null;
        }

        $anchor = self::date($billingStartsOn);
        $query = self::date($asOf);
        $monthOffset = ((int) $query->format('Y') - (int) $anchor->format('Y')) * 12
            + (int) $query->format('n') - (int) $anchor->format('n');
        $start = self::boundary($anchor, $monthOffset);
        if ($query < $start) {
            $monthOffset--;
            $start = self::boundary($anchor, $monthOffset);
        }
        $next = self::boundary($anchor, $monthOffset + 1);

        $scheduledEnd = $next->modify('-1 day')->format('Y-m-d');
        return [
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $endsOn !== null ? min($scheduledEnd, $endsOn) : $scheduledEnd,
            'scheduled_ends_on' => $scheduledEnd,
        ];
    }

    private static function boundary(DateTimeImmutable $anchor, int $months): DateTimeImmutable
    {
        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        $base = $anchor->setDate($year, $month, 1)->modify(($months >= 0 ? '+' : '').$months.' months');
        $day = min((int) $anchor->format('j'), (int) $base->format('t'));
        return $base->setDate((int) $base->format('Y'), (int) $base->format('n'), $day);
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value.' 00:00:00', new DateTimeZone('UTC'));
    }
}
