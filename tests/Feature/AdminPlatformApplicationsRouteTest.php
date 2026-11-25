<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformApplicationsRouteTest extends TestCase
{
    public function test_application_registry_is_platform_scoped_and_permission_gated(): void
    {
        $route = Route::getRoutes()->getByName('api.admin.platform.applications.index');

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('auth.permission:platform.applications.read', $route->gatherMiddleware());
        $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
    }
}
