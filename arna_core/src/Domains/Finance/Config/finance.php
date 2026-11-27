<?php

declare(strict_types=1);

/**
 * Finance domain integration defaults.
 *
 * Preserve the historical /api/finance URL contract while routing and
 * migration ownership remain inside the Finance domain.
 */
return [
    'enabled' => true,
    'routes' => [
        'middleware' => ['api', 'auth:sanctum', 'tenant.membership'],
        'prefix' => 'api/finance',
        'name_prefix' => 'api.finance.',
    ],
];
