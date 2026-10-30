<?php

declare(strict_types=1);

/**
 * Organization domain configuration.
 *
 * This file is the domain-owned source of route, migration, and enablement
 * settings. Shared Laravel bootstrapping may register this domain provider,
 * but Organization-specific behavior remains within this directory.
 */
return [
    'enabled' => (bool) env('ARNA_ORGANIZATION_ENABLED', true),

    'routes' => [
        'prefix' => env('ARNA_ORGANIZATION_ROUTE_PREFIX', 'api/organization'),
        'name' => 'api.organization.',
        'middleware' => ['api', 'auth:sanctum', 'tenant.membership'],
    ],

    'database' => [
        'migrations' => __DIR__.'/../Database/Migrations',
    ],
];
