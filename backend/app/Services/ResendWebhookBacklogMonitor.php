<?php
declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global operator-only metrics. Unmatched provider UUIDs cannot be safely
 * attributed to a tenant, so this class must not back a tenant-scoped API.
 */
final class ResendWebhookBacklogMonitor
{
    /**
     * @return array<string, int|string|null>
     */
    public function snapshot(
        int $warnUnknownAfterMinutes = 15,
        int $criticalKnownAfterMinutes = 2,
        int $criticalPendingCount = 100,
    ): array {
        if ($warnUnknownAfterMinutes < 1 || $warnUnknownAfterMinutes > 10080
            || $criticalKnownAfterMinutes < 1 || $criticalKnownAfterMinutes > 10080
            || $criticalPendingCount < 1 || $criticalPendingCount > 100000) {
            throw new InvalidArgumentException('Invalid Resend backlog monitoring thresholds.');
        }

        $now = CarbonImmutable::now('UTC');
        $pending = DB::table('collector_mail_webhook_events')->where('outcome', 'pending');

        // Keep status aggregation bounded in memory, independent of row count.
        $totals = DB::table('collector_mail_webhook_events')
            ->selectRaw('outcome, COUNT(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $pendingCount = (int) ($totals['pending'] ?? 0);
        $known = $this->withKnownProviderUuid(clone $pending);
        $knownCount = (int) (clone $known)->count();

        $knownOverdue = (int) (clone $known)
            ->where('received_at', '<=', $now->subMinutes($criticalKnownAfterMinutes))
            ->count();

        $unknownCutoff = $now->subMinutes($warnUnknownAfterMinutes);
        $allOld = (int) (clone $pending)
            ->where('received_at', '<=', $unknownCutoff)->count();
        $knownOld = (int) (clone $known)
            ->where('received_at', '<=', $unknownCutoff)->count();
        $unknownOverdue = max(0, $allOld - $knownOld);

        $status = $knownOverdue > 0 || $pendingCount >= $criticalPendingCount
            ? 'critical'
            : ($unknownOverdue > 0 ? 'warning' : 'healthy');

        $oldest = (clone $pending)->min('received_at');

        return [
            'status' => $status,
            'checked_at' => $now->toIso8601String(),
            'pending_total' => $pendingCount,
            'pending_known' => $knownCount,
            'pending_unknown' => max(0, $pendingCount - $knownCount),
            'overdue_known' => $knownOverdue,
            'overdue_unknown' => $unknownOverdue,
            // Historical counters, NOT a timestamp of when a row expired.
            'applied_total' => (int) ($totals['applied'] ?? 0),
            'stale_total' => (int) ($totals['stale'] ?? 0),
            'expired_total' => (int) ($totals['expired'] ?? 0),
            'oldest_pending_at' => $oldest === null
                ? null
                : CarbonImmutable::parse((string) $oldest, 'UTC')->toIso8601String(),
            'warn_unknown_after_minutes' => $warnUnknownAfterMinutes,
            'critical_known_after_minutes' => $criticalKnownAfterMinutes,
            'critical_pending_count' => $criticalPendingCount,
        ];
    }

    private function withKnownProviderUuid(Builder $query): Builder
    {
        return $query->where(static function (Builder $matching): void {
            $matching
                ->whereExists(static function (Builder $sub): void {
                    $sub->selectRaw('1')
                        ->from('collector_incident_notifications as notices')
                        ->whereColumn(
                            'notices.provider_email_id',
                            'collector_mail_webhook_events.provider_email_id'
                        );
                })
                ->orWhereExists(static function (Builder $sub): void {
                    $sub->selectRaw('1')
                        ->from('collector_notification_replays as replays')
                        ->whereColumn(
                            'replays.provider_email_id',
                            'collector_mail_webhook_events.provider_email_id'
                        );
                });
        });
    }
}
