<?php
declare(strict_types=1);

return [
    'collector_heartbeat'=>[
        // Heartbeat is independent of SNMP polling and successful upload.
        'max_silence_seconds'=>(int)env('NEXA_HEARTBEAT_MAX_SILENCE_SECONDS',180),
    ],
    'collector_health_alerts'=>[
        // Operator opt-in: do not flood a newly installed deployment with alerts.
        'enabled'=>(bool)env('NEXA_COLLECTOR_HEALTH_ALERTS_ENABLED',false),
        // Detection threshold independent from the UI freshness badge.
        'silence_seconds'=>(int)env('NEXA_COLLECTOR_HEALTH_SILENCE_SECONDS',300),
    ],
    'collector_notifications'=>[
        // Explicit opt-in; mail transport must be configured outside source.
        'enabled'=>(bool)env('NEXA_COLLECTOR_NOTIFICATIONS_ENABLED',false),
        'escalation_minutes'=>(int)env('NEXA_COLLECTOR_ESCALATION_MINUTES',60),
    ],
    'telemetry_gap' => [
        // Disabled until explicitly enabled by the installation operator.
        'enabled' => (bool) env('NEXA_TELEMETRY_GAP_ENABLED', false),
        // Silence since most recent *collected_at*, not merely a late retry.
        'threshold_minutes' => (int) env('NEXA_TELEMETRY_GAP_MINUTES', 60),
        // Require two new, distinct samples sufficiently separated in time.
        'recovery_minutes' => (int) env('NEXA_TELEMETRY_RECOVERY_MINUTES', 10),
    ],
];
