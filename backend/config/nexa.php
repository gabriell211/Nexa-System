<?php
declare(strict_types=1);

return [
    // Existing bearer-token clients may opt in temporarily during migration.
    // The browser portal must always use session cookies, not bearer tokens.
    'allow_legacy_token_login' => (bool) env('NEXA_ALLOW_LEGACY_TOKEN_LOGIN', false),
];
