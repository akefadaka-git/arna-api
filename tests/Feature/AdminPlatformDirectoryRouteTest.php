<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformDirectoryRouteTest extends TestCase
{
    public function test_platform_identity_rbac_and_tenant_directories_use_separate_global_permissions(): void
    {
        $expected = [
            'api.admin.platform.identity.users.index' => 'auth.permission:platform.identity.read',
            'api.admin.platform.rbac.roles.index' => 'auth.permission:platform.rbac.read',
            'api.admin.platform.rbac.permissions.index' => 'auth.permission:platform.rbac.read',
            'api.admin.platform.tenants.index' => 'auth.permission:platform.tenancy.read',
            'api.admin.platform.tenants.memberships.index' => 'auth.permission:platform.tenancy.read',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, sprintf('Missing route %s', $name));
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains($permission, $middleware);
            $this->assertNotContains('tenant.membership', $middleware);
        }
    }
}
