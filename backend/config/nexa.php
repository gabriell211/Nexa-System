<?php
declare(strict_types=1);

return [
    'telemetry_gap' => [
        // Disabled until explicitly enabled by the installation operator.
        'enabled' => (bool) env('NEXA_TELEMETRY_GAP_ENABLED', false),
        // Silence since most recent *collected_at*, not merely a late retry.
        'threshold_minutes' => (int) env('NEXA_TELEMETRY_GAP_MINUTES', 60),
        // Require two new, distinct samples sufficiently separated in time.
        'recovery_minutes' => (int) env('NEXA_TELEMETRY_RECOVERY_MINUTES', 10),
    ],
];
