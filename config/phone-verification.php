<?php

declare(strict_types=1);

return [
    // Configure an HTTPS JSON SMS gateway accepting {to, message, sender?}.
    // Never place verification codes in logs or client responses.
    'gateway_url' => env('PHONE_VERIFICATION_GATEWAY_URL'),
    'gateway_token' => env('PHONE_VERIFICATION_GATEWAY_TOKEN'),
    'sender' => env('PHONE_VERIFICATION_SENDER'),
    'expiry_minutes' => (int) env('PHONE_VERIFICATION_EXPIRY_MINUTES', 10),
    'max_attempts' => (int) env('PHONE_VERIFICATION_MAX_ATTEMPTS', 5),
];
