<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use MaaleArna\MASES\Core\Tenancy\Middleware\TenantMembershipMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * TenantMembershipTest
 *
 * Tests the TenantMembershipMiddleware to ensure it correctly verifies
 * that the authenticated user is a member of the resolved tenant.
 *
 * These tests use the real TenantContextMiddleware and TenantMembershipMiddleware
 * with a real resolver (header-based) to test the full flow.
 *
 * -----------------------------------------------------------------------------
 * Revision history
 * -----------------------------------------------------------------------------
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — R-18 alignment. The unauthenticated-request assertion in
 *         `test_unauthenticated_user_rejected` is updated from the
 *         historical ad-hoc code `AUTH_REQUIRED` to the canonical
 *         `AUTH_UNAUTHENTICATED`.
 *
 *         Background: prior to R-18,
 *         `TenantMembershipMiddleware` threw a `TenantException` with
 *         the bespoke error code `AUTH_REQUIRED` when the request was
 *         unauthenticated. `AUTH_REQUIRED` was a literal, not a
 *         constant on any MASES exception class, and duplicated the
 *         semantic that `AuthenticationException::ERROR_UNAUTHENTICATED`
 *         already represents. D-15 (H-13) closed the same class of
 *         inconsistency in `App\Http\Middleware\Authenticate`;
 *         R-18 closes it here.
 *
 *         R-18 File 1 changed `TenantMembershipMiddleware` to throw
 *         `AuthenticationException::unauthenticated()` instead. That
 *         exception carries `AUTH_UNAUTHENTICATED` and HTTP status 401
 *         via the `CoreException` branch of the canonical renderer in
 *         `bootstrap/app.php`. This file's assertion is updated to
 *         match.
 *
 *         No other test in this file changes. The tenant-required,
 *         membership-denied, and success-path tests continue to
 *         assert their original codes (`TENANT_REQUIRED`,
 *         `TENANT_ACCESS_DENIED`) — those branches were not touched
 *         by R-18.
 *
 *         The related test in `TenantRequiredRouteTest::unauthenticated_request_is_rejected_with_401`
 *         asserts only `assertStatus(401)` with no code assertion, so
 *         it is unaffected and requires no change.
 *
 * @package Tests\Feature
 * @version 1.0.1
 */
final class TenantMembershipTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);

        // Register the tenant membership middleware alias for testing.
        // In real app, it's registered in bootstrap/app.php.
        $this->app['router']->aliasMiddleware('tenant.membership', TenantMembershipMiddleware::class);
    }

    /**
     * Test that a user who is a member of the tenant can access the route.
     *
     * @return void
     */
    public function test_member_can_access_tenant_route(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'test-tenant']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);

        $this->actingAs($user, 'sanctum');

        // Create a test route that requires tenant membership.
        $this->app['router']->get('/test-membership', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'tenant.membership']);

        // Send request with the tenant header.
        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-membership');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test that a user who is NOT a member of the tenant is denied access.
     *
     * @return void
     */
    public function test_non_member_denied_access(): void
    {
        $tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
        $tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);

        $user = User::factory()->create();
        $user->tenants()->attach($tenantA->id); // Only member of tenant A.

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-membership', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'tenant.membership']);

        // Attempt to access with tenant B header.
        $response = $this->withHeader('X-Tenant-ID', (string) $tenantB->id)
            ->getJson('/test-membership');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'TENANT_ACCESS_DENIED',
            ],
        ]);
    }

    /**
     * Test that a user without any tenant membership is denied access.
     *
     * @return void
     */
    public function test_user_without_membership_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(); // No membership.

        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-membership', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'tenant.membership']);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-membership');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'TENANT_ACCESS_DENIED',
            ],
        ]);
    }

    /**
     * Test that the middleware requires a tenant if not present.
     *
     * @return void
     */
    public function test_tenant_required_if_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->app['router']->get('/test-membership', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'tenant.membership']);

        // No tenant header sent.
        $response = $this->getJson('/test-membership');

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'TENANT_REQUIRED',
            ],
        ]);
    }

    /**
     * Test that the middleware can skip tenant requirement when parameter is false.
     *
     * @return void
     */
    public function test_tenant_not_required_when_parameter_false(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        // Register a route with parameter 'false' to skip tenant requirement.
        $this->app['router']->get('/test-membership-optional', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'tenant.membership:false']);

        $response = $this->getJson('/test-membership-optional');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test that unauthenticated users are rejected.
     *
     * A request that reaches `tenant.membership` without an authenticated
     * user is rejected with the canonical MASES authentication code
     * `AUTH_UNAUTHENTICATED` and HTTP status 401.
     *
     * Prior to R-18 the middleware produced the ad-hoc code
     * `AUTH_REQUIRED`; that string was not a constant on any MASES
     * exception class and duplicated the semantic that
     * `AuthenticationException::ERROR_UNAUTHENTICATED` already
     * represents. See the class docblock §"Revision history" for the
     * full rationale.
     *
     * @return void
     */
    public function test_unauthenticated_user_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $this->app['router']->get('/test-membership', function () {
            return response()->json(['success' => true]);
        })->middleware(['tenant.membership']);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/test-membership');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_UNAUTHENTICATED',
            ],
        ]);
    }
}