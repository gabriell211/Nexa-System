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
        'driver'=>env('NEXA_COLLECTOR_MAIL_DRIVER','laravel'),
        'resend_api_key'=>env('NEXA_RESEND_API_KEY'),
        'resend_webhooks_enabled'=>(bool)env('NEXA_RESEND_WEBHOOKS_ENABLED',false),
        'resend_webhook_secret'=>env('NEXA_RESEND_WEBHOOK_SECRET'),
        'resend_reconcile_batch'=>(int)env('NEXA_RESEND_RECONCILE_BATCH',100),
        'resend_pending_hours'=>(int)env('NEXA_RESEND_PENDING_HOURS',72),
        'escalation_minutes'=>(int)env('NEXA_COLLECTOR_ESCALATION_MINUTES',60),
        'max_per_run'=>(int)env('NEXA_COLLECTOR_MAIL_MAX_PER_RUN',50),
        'max_per_tenant_hour'=>(int)env('NEXA_COLLECTOR_MAIL_MAX_PER_TENANT_HOUR',20),
        'max_global_hour'=>(int)env('NEXA_COLLECTOR_MAIL_MAX_GLOBAL_HOUR',100),
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
