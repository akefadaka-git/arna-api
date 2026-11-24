<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPersistentDomainRegistryTest extends TestCase
{
    public function test_domain_registry_mutations_have_separate_global_permission_boundaries(): void
    {
        $expected = [
            'api.admin.platform.domains.index' => 'auth.permission:platform.domains.read',
            'api.admin.platform.domains.store' => 'auth.permission:platform.domains.manage',
            'api.admin.platform.domains.update' => 'auth.permission:platform.domains.manage',
            'api.admin.platform.domains.verify' => 'auth.permission:platform.domains.verify',
            'api.admin.platform.domains.history' => 'auth.permission:platform.domains.read',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route {$name}");
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains($permission, $middleware);
            $this->assertNotContains('tenant.membership', $middleware);
        }
    }

    public function test_persistent_registry_schema_separates_records_verification_and_audit(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_11_24_000001_create_platform_domain_registry_tables.php'));

        $this->assertIsString($migration);
        $this->assertStringContainsString("Schema::create('platform_domains'", $migration);
        $this->assertStringContainsString("Schema::create('platform_domain_verifications'", $migration);
        $this->assertStringContainsString("Schema::create('platform_domain_audit_events'", $migration);
        $this->assertStringContainsString("'challenge_hash'", $migration);
    }

    public function test_domain_challenge_is_hashed_and_never_written_to_audit_state(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformDomainRegistryController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString("hash('sha256', $token)", $controller);
        $this->assertStringContainsString("hash_equals($verification->challenge_hash", $controller);
        $this->assertStringContainsString("'record_value' => $token", $controller);
        $this->assertStringNotContainsString("'record_value' => $data['record_value']", $controller);
    }
}
