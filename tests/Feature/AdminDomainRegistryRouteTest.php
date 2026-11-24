<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminDomainRegistryRouteTest extends TestCase
{
    public function test_domain_registry_is_platform_scoped_and_permission_gated(): void
    {
        $route = Route::getRoutes()->getByName('api.admin.platform.domains.index');

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('auth.permission:platform.domains.read', $route->gatherMiddleware());
        $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
    }

    public function test_domain_inventory_does_not_claim_external_provider_verification(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformDomainRegistryController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString("'ownership_state' => 'not_verified'", $controller);
        $this->assertStringContainsString("'dns_state' => 'not_observed'", $controller);
        $this->assertStringContainsString("'tls_state' => 'not_observed'", $controller);
        $this->assertStringContainsString("'live_mutations_enabled' => false", $controller);
    }
}
