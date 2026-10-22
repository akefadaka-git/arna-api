<?php

declare(strict_types=1);

return [
    // Delivery is intentionally not configured here; the repository has no
    // SMS provider adapter. Do not expose a request-code endpoint until one is
    // selected, configured, and tested.
    'expiry_minutes' => (int) env('PHONE_VERIFICATION_EXPIRY_MINUTES', 10),
    'max_attempts' => (int) env('PHONE_VERIFICATION_MAX_ATTEMPTS', 5),
];
