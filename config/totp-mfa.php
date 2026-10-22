<?php

declare(strict_types=1);

return [
    'issuer' => env('TOTP_MFA_ISSUER', env('APP_NAME', 'ARNA')),
    'digits' => 6,
    'period_seconds' => 30,
    'allowed_clock_drift_steps' => 1,
    'login_challenge_minutes' => 5,
    'max_login_attempts' => 5,
    'recovery_code_count' => 10,
];
