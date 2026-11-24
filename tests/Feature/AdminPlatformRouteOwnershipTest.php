<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformRouteOwnershipTest extends TestCase
{
    public function test_platform_read_routes_use_their_dedicated_global_permission_gates(): void
    {
        $routes = Route::getRoutes();

        $expected = [
            'api.admin.platform.identity.users.index' => 'auth.permission:platform.identity.read',
            'api.admin.platform.rbac.roles.index' => 'auth.permission:platform.rbac.read',
            'api.admin.platform.rbac.permissions.index' => 'auth.permission:platform.rbac.read',
            'api.admin.platform.tenants.index' => 'auth.permission:platform.tenancy.read',
            'api.admin.platform.tenants.memberships.index' => 'auth.permission:platform.tenancy.read',
        ];

        foreach ($expected as $name => $permissionMiddleware) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, sprintf('Expected route [%s] to be registered.', $name));
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($permissionMiddleware, $route->gatherMiddleware());
            $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
        }
    }

    public function test_legacy_overlapping_platform_and_tenant_route_names_are_not_registered(): void
    {
        $routes = Route::getRoutes();

        $this->assertNull($routes->getByName('api.admin.identity.users.index'));
        $this->assertNull($routes->getByName('api.admin.tenants.index'));
    }

    public function test_tenant_scoped_permissions_do_not_appear_on_platform_routes(): void
    {
        $routes = Route::getRoutes();

        foreach ([
            'api.admin.platform.identity.users.index',
            'api.admin.platform.rbac.roles.index',
            'api.admin.platform.rbac.permissions.index',
            'api.admin.platform.tenants.index',
            'api.admin.platform.tenants.memberships.index',
        ] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('auth.permission:user.list', $middleware);
            $this->assertNotContains('auth.permission:role.list', $middleware);
            $this->assertNotContains('auth.permission:permission.list', $middleware);
        }
    }
}
