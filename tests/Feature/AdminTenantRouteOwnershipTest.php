<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\Route;
use Tests\\TestCase;

final class AdminTenantRouteOwnershipTest extends TestCase
{
    public function test_tenant_membership_lifecycle_routes_require_authenticated_active_tenant_membership_and_tenant_permission(): void
    {
        $routes = Route::getRoutes();
        $expected = [
            'api.admin.tenant.memberships.users.attach',
            'api.admin.tenant.memberships.users.suspend',
            'api.admin.tenant.memberships.users.reactivate',
            'api.admin.tenant.memberships.users.revoke',
        ];

        foreach ($expected as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, sprintf('Expected route [%s] to be registered.', $name));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('tenant.membership', $middleware);
            $this->assertContains('auth.permission:tenant.membership.manage', $middleware);
            $this->assertStringNotContainsString('{tenant}', $route->uri());
            $this->assertStringNotContainsString('tenant_id', $route->uri());
        }
    }

    public function test_tenant_membership_lifecycle_routes_are_not_platform_wide_routes(): void
    {
        $routes = Route::getRoutes();

        foreach ([
            'api.admin.tenant.memberships.users.attach',
            'api.admin.tenant.memberships.users.suspend',
            'api.admin.tenant.memberships.users.reactivate',
            'api.admin.tenant.memberships.users.revoke',
        ] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route);
            $this->assertNotContains('auth.permission:platform.tenancy.read', $route->gatherMiddleware());
        }
    }
}
