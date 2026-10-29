<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('ARNA_ORGANIZATION_ENABLED', true),
    'routes' => [
        'prefix' => 'api/organization',
        'middleware' => ['api', 'auth:sanctum', 'tenant.membership'],
    ],
    'hierarchy' => [
        'max_depth' => 100,
    ],
    'directory' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],
];
