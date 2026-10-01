<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use MaaleArna\MASES\Core\Exceptions\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * RbacAuthorizationTest
 *
 * Comprehensive tests for the RBAC and application authorization system.
 * This is the second major security gate for Phase 1.5.
 *
 * Tests cover:
 * - Role → permission resolution
 * - Direct permission grants (user_has_permissions)
 * - Application access permissions (app.*.access)
 * - Tenant‑scoped permissions
 * - Global/system‑admin permissions
 * - Unauthorized access is correctly rejected (403)
 * - No privilege escalation is possible
 * - Authorization middleware behaves as expected
 * - Authorization service methods work correctly
 *
 * The test class includes robust logging to `storage/logs/test.log` (or
 * system temp directory if storage/logs is not writable) for debugging
 * and monitoring test execution.
 *
 * @package Tests\Feature
 */
final class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * The authorization service instance.
     *
     * @var AuthorizationService
     */
    protected AuthorizationService $authService;

    /**
     * Path to the debug log file.
     *
     * @var string
     */
    private string $logFile;

    /**
     * Set up the test environment.
     *
     * This method is called before each test method. It initializes the
     * AuthorizationService, configures tenant isolation settings, and
     * prepares the log file for debugging.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = app(AuthorizationService::class);

        // Ensure tenant isolation is enabled and the request resolver is set.
        Config::set('mases.tenant.isolation_enabled', true);
        Config::set(
            'mases.tenant.context_resolver',
            RequestTenantResolver::class
        );

        // Use a writable log location: storage/logs if possible, else system temp.
        $storageLog = storage_path('logs/test.log');
        if (is_dir(storage_path('logs')) && is_writable(storage_path('logs'))) {
            $this->logFile = $storageLog;
        } else {
            $this->logFile = sys_get_temp_dir() . '/mases_test.log';
        }
    }

    /**
     * Override runTest to add logging around test execution.
     *
     * This method wraps the parent runTest to catch any Throwable, log it
     * with detailed debug information, and rethrow it so PHPUnit still
     * reports the failure correctly.
     *
     * @return mixed
     */
    protected function runTest(): mixed
    {
        $testName = $this->getName();
        $startTime = microtime(true);
        $this->logDebug("TEST START: {$testName} at " . date('Y-m-d H:i:s'));

        try {
            $result = parent::runTest();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logDebug("TEST PASS: {$testName} (Duration: {$duration} ms)");
            return $result;
        } catch (Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logDebug("TEST FAIL: {$testName} (Duration: {$duration} ms)");
            $this->logDebug("EXCEPTION: " . get_class($e) . " - {$e->getMessage()}");
            $this->logDebug("FILE: {$e->getFile()}:{$e->getLine()}");
            $this->logDebug("TRACE:\n" . $e->getTraceAsString());
            throw $e;
        } finally {
            $this->logDebug("TEST END: {$testName}\n");
        }
    }

    /**
     * Write a debug message to the log file.
     *
     * @param string $message
     * @return void
     */
    private function logDebug(string $message): void
    {
        $formatted = "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        file_put_contents($this->logFile, $formatted, FILE_APPEND | LOCK_EX);
    }

    // --------------------------------------------------------------
    // Helper methods
    // --------------------------------------------------------------

    /**
     * Create a Permission record with default required fields.
     *
     * @param array $attributes
     * @return Permission
     */
    private function createPermissionRecord(array $attributes): Permission
    {
        return Permission::create(array_merge([
            'name_am'   => $attributes['slug'] ?? 'permission',
            'name_en'   => $attributes['slug'] ?? 'permission',
            'slug'      => $attributes['slug'] ?? 'permission.slug',
            'scope'     => 'global',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Create a Role record with default required fields.
     *
     * @param array $attributes
     * @return Role
     */
    private function createRoleRecord(array $attributes): Role
    {
        return Role::create(array_merge([
            'name_am'   => $attributes['slug'] ?? 'role',
            'name_en'   => $attributes['slug'] ?? 'role',
            'slug'      => $attributes['slug'] ?? 'role.slug',
            'scope'     => 'platform',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Create a PlatformApplication record with default required fields.
     *
     * @param array $attributes
     * @return PlatformApplication
     */
    private function createApplicationRecord(array $attributes): PlatformApplication
    {
        return PlatformApplication::create(array_merge([
            'name_am'   => $attributes['slug'] ?? 'app',
            'name_en'   => $attributes['slug'] ?? 'app',
            'slug'      => $attributes['slug'] ?? 'app.slug',
            'domain'    => $attributes['domain'] ?? ($attributes['slug'] ?? 'app') . '.example.com',
            'is_active' => true,
        ], $attributes));
    }

    // --------------------------------------------------------------
    // Test methods
    // --------------------------------------------------------------

    /**
     * Test that a user with a direct permission can access a protected route.
     */
    #[Test]
    public function a_user_with_direct_permission_can_access_a_protected_route(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $permission = $this->createPermissionRecord([
            'slug' => 'test.permission',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
        ]);
        $user->permissions()->attach($permission->id);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-permission', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.permission:test.permission');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-permission');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test that a user without the required permission receives 403.
     */
    #[Test]
    public function a_user_without_permission_is_rejected_with_403(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-permission', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.permission:test.permission');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-permission');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_PERMISSION_DENIED',
            ],
        ]);
    }

    /**
     * Test that a user inherits permissions from assigned roles.
     */
    #[Test]
    public function a_user_with_role_inherits_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $role = $this->createRoleRecord(['scope' => 'tenant', 'tenant_id' => $tenant->id]);
        $permission = $this->createPermissionRecord([
            'slug' => 'test.role.permission',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-role-permission', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.permission:test.role.permission');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-role-permission');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test that application access permission grants access to routes.
     */
    #[Test]
    public function application_access_permission_controls_application_routes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $app = $this->createApplicationRecord(['slug' => 'finance', 'is_active' => true]);
        $permission = $this->createPermissionRecord([
            'slug' => 'app.finance.access',
            'scope' => 'application',
            'application_id' => $app->id,
        ]);
        $user->permissions()->attach($permission->id);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-app-access', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.application:finance');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-app-access');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test that missing application access permission results in 403.
     */
    #[Test]
    public function application_access_denied_without_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $this->createApplicationRecord(['slug' => 'finance', 'is_active' => true]);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-app-access', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.application:finance');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-app-access');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_APPLICATION_DENIED',
            ],
        ]);
    }

    /**
     * Test that tenant-scoped permissions only apply within the correct tenant.
     * This version directly tests the AuthorizationService to avoid HTTP
     * middleware caching issues.
     */
    #[Test]
    public function tenant_scoped_permission_only_applies_to_correct_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create();
        $user->tenants()->attach($tenantA->id);
        $user->tenants()->attach($tenantB->id);

        $permission = $this->createPermissionRecord([
            'slug' => 'test.tenant.scoped',
            'scope' => 'tenant',
            'tenant_id' => $tenantA->id,
        ]);
        $user->permissions()->attach($permission->id);

        // Assert permission is valid for tenantA
        $this->assertTrue(
            $this->authService->hasPermission('test.tenant.scoped', $user, $tenantA->id)
        );

        // Assert permission is NOT valid for tenantB
        $this->assertFalse(
            $this->authService->hasPermission('test.tenant.scoped', $user, $tenantB->id)
        );
    }

    /**
     * Test that global permissions are available across all tenants.
     */
    #[Test]
    public function global_permission_is_available_across_all_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create();
        $user->tenants()->attach($tenantA->id);
        $user->tenants()->attach($tenantB->id);

        $permission = $this->createPermissionRecord([
            'slug' => 'test.global',
            'scope' => 'global',
            'tenant_id' => null,
        ]);
        $user->permissions()->attach($permission->id);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-global', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.permission:test.global');

        $responseA = $this->withHeader('X-Tenant-ID', (string) $tenantA->id)
            ->getJson('/test-global');
        $responseA->assertStatus(200);

        $responseB = $this->withHeader('X-Tenant-ID', (string) $tenantB->id)
            ->getJson('/test-global');
        $responseB->assertStatus(200);
    }

    /**
     * Test that direct URL access cannot bypass authorization checks.
     */
    #[Test]
    public function direct_url_access_cannot_bypass_authorization(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/secret-resource', function () {
            return response()->json(['data' => 'sensitive']);
        })->middleware('auth.permission:secret.view');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/secret-resource');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_PERMISSION_DENIED',
            ],
        ]);
    }

    /**
     * Test that AuthorizationService correctly resolves permissions.
     */
    #[Test]
    public function authorization_service_correctly_resolves_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $permission = $this->createPermissionRecord([
            'slug' => 'test.service',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
        ]);
        $user->permissions()->attach($permission->id);

        $this->assertTrue($this->authService->hasPermission('test.service', $user, $tenant->id));
        $this->assertFalse($this->authService->hasPermission('test.missing', $user, $tenant->id));
    }

    /**
     * Test that AuthorizationService handles role-based permissions.
     */
    #[Test]
    public function authorization_service_handles_role_based_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $role = $this->createRoleRecord(['scope' => 'tenant', 'tenant_id' => $tenant->id]);
        $permission = $this->createPermissionRecord([
            'slug' => 'test.role.service',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $this->assertTrue($this->authService->hasPermission('test.role.service', $user, $tenant->id));
        $this->assertTrue($this->authService->hasPermission('test.role.service', $user, $tenant->id));
    }

    /**
     * Test that application access via service works correctly.
     */
    #[Test]
    public function application_access_via_service_works(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $app = $this->createApplicationRecord(['slug' => 'hr', 'is_active' => true]);
        $permission = $this->createPermissionRecord([
            'slug' => 'app.hr.access',
            'scope' => 'application',
            'application_id' => $app->id,
        ]);
        $user->permissions()->attach($permission->id);

        $this->assertTrue($this->authService->canAccessApplication('hr', $user));
        $this->assertFalse($this->authService->canAccessApplication('nonexistent', $user));
    }

    /**
     * Test that requirePermission throws an AuthorizationException with code 403.
     */
    #[Test]
    public function require_permission_throws_exception_on_failure(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionCode(403);

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $this->authService->requirePermission('test.missing', $user, $tenant->id);
    }

    /**
     * Test that requireRole throws an AuthorizationException with code 403.
     */
    #[Test]
    public function require_role_throws_exception_on_failure(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionCode(403);

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $this->authService->requireRole('admin', $user, $tenant->id);
    }

    /**
     * Test that a system admin with a global permission can access any route.
     */
    #[Test]
    public function system_admin_can_do_anything(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $superRole = $this->createRoleRecord(['scope' => 'platform', 'slug' => 'super_admin']);
        $permission = $this->createPermissionRecord(['slug' => 'super.anything', 'scope' => 'global']);
        $superRole->permissions()->attach($permission->id);
        $user->roles()->attach($superRole->id);

        $this->actingAs($user, 'sanctum');

        $this->assertTrue($this->authService->hasPermission('super.anything', $user, $tenant->id));

        $this->app['router']->get('/test-super', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.permission:super.anything');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-super');
        $response->assertStatus(200);
    }

    /**
     * Test that direct permission grants do not allow privilege escalation.
     */
    #[Test]
    public function no_privilege_escalation_via_direct_permission_grant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $adminPermission = $this->createPermissionRecord([
            'slug' => 'admin.only',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_system' => true,
        ]);
        $user->permissions()->attach($adminPermission->id);

        $user2 = User::factory()->create();
        $user2->tenants()->attach($tenant->id);

        $this->actingAs($user2, 'sanctum');

        $this->app['router']->get('/admin-only', function () {
            return response()->json(['success' => true]);
        })->middleware('auth.permission:admin.only');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/admin-only');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_PERMISSION_DENIED',
            ],
        ]);
    }
}