<?php

declare(strict_types=1);

$keys = json_decode((string) env('AGE_REGISTER_ATTESTATION_KEYS', '{}'), true);

return [
    'register_attestation' => [
        'active_key_id' => env('AGE_REGISTER_ATTESTATION_ACTIVE_KEY_ID'),
        'keys' => is_array($keys) ? $keys : [],
    ],
];
