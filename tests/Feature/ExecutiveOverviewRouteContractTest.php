<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ExecutiveOverviewRouteContractTest extends TestCase
{
    public function test_overview_route_is_get_only_tenant_scoped_and_requires_finance_permissions(): void
    {
        $route = Route::getRoutes()->getByName('api.dashboard.v1.overview');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('tenant.membership', $route->gatherMiddleware());
        $this->assertContains('auth.permission:finance.entity.view', $route->gatherMiddleware());
        $this->assertContains('auth.permission:finance.report.view', $route->gatherMiddleware());
        $this->assertSame('api/dashboard/v1/overview', $route->uri());
    }
}
