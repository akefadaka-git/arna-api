<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformOperationsTest extends TestCase
{
    public function test_integration_and_operations_routes_are_platform_permission_gated(): void
    {
        $expected = [
            'api.admin.platform.integrations.index' => 'auth.permission:platform.integrations.read',
            'api.admin.platform.operations.health' => 'auth.permission:platform.operations.read',
            'api.admin.platform.operations.observations' => 'auth.permission:platform.operations.read',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($permission, $route->gatherMiddleware());
            $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
        }
    }

    public function test_provider_registry_never_exposes_credential_ciphertext_or_claims_live_mutations(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformOperationsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString("'credentials_configured' =>", $controller);
        $this->assertStringContainsString("'credential_values_exposed' => false", $controller);
        $this->assertStringContainsString("'provider_mutations_enabled' => false", $controller);
        $this->assertStringNotContainsString("'credential_ciphertext' =>", $controller);
    }

    public function test_health_endpoint_distinguishes_observed_from_unobserved_components(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformOperationsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString("DB::selectOne('SELECT 1 AS health_check')", $controller);
        $this->assertStringContainsString("'status' => 'not_observed'", $controller);
        $this->assertStringContainsString("'active_provider_probes' => false", $controller);
    }
}
