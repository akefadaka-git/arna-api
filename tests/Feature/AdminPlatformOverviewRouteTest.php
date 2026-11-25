<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformOverviewRouteTest extends TestCase
{
    public function test_platform_overview_is_global_and_permission_gated(): void
    {
        $route = Route::getRoutes()->getByName('api.admin.platform.overview');

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('auth.permission:platform.overview.read', $route->gatherMiddleware());
        $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
    }
}
