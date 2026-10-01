<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TenantIsolationTest
 *
 * Verifies that tenant isolation is correctly enforced across the API.
 * This is a critical security test suite for Phase 1.5 Reality Check.
 *
 * -----------------------------------------------------------------------------
 * Design Philosophy
 * -----------------------------------------------------------------------------
 * The `/api/tenant-context` endpoint is a DIAGNOSTIC endpoint. Its purpose
 * is to report the currently resolved tenant context for the authenticated
 * user — even when the user is not a member of that tenant, or when no
 * tenant is present at all.
 *
 * This is intentional:
 *   - It allows front-end and internal tools to debug "why can't I access
 *     tenant X?" without needing direct database access.
 *   - It does NOT leak any data the user didn't already know (they sent
 *     the tenant ID in the request themselves).
 *   - It ALWAYS returns 200, never 4xx for missing / invalid tenants.
 *
 * Enforcement of membership happens separately, in the
 * `TenantMembershipMiddleware`, which is applied to actual tenant-scoped
 * routes (files, transactions, etc.) — NOT to the diagnostic endpoint.
 *
 * -----------------------------------------------------------------------------
 * Envelope contract (Phase 2A CR-003a / CR-021b)
 * -----------------------------------------------------------------------------
 * The `/api/health` endpoint returns the canonical MASES envelope:
 *
 *     { "success": true, "data": { "status": "ok", "timestamp": …,
 *                                  "version": …, "environment": … } }
 *
 * The health payload is nested under `data`. `public_endpoints_accessible_without_tenant()`
 * asserts on `data.status` and on the nested structure accordingly.
 *
 * -----------------------------------------------------------------------------
 * Coverage
 * -----------------------------------------------------------------------------
 *   ✓ Members can see their tenant context.
 *   ✓ Non-members see `is_member: false` (no data leak).
 *   ✓ Users with no memberships see `is_member: false`.
 *   ✓ Public endpoints work without tenant context.
 *   ✓ Missing tenant context returns `tenant_id: null`, not an error.
 *   ✓ Tenant-required route without tenant context → 400 (see below).
 *   ✓ Subdomain resolution delegated to TenantResolverTest (unit).
 *
 * @package Tests\Feature
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * Set up the test environment.
     *
     * Enables tenant isolation and ensures a clean slate for every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure tenant isolation is enabled for these tests.
        Config::set('mases.tenant.isolation_enabled', true);

        // No default tenant should leak between tests.
        Config::set('mases.tenant.default_tenant_id', null);

        // Use the standard request resolver so header-based resolution
        // matches production behavior.
        Config::set(
            'mases.tenant.context_resolver',
            RequestTenantResolver::class
        );
    }

    // =========================================================================
    // Test 1: Member Can Access Their Tenant
    // =========================================================================

    /**
     * Test that a member user can access their own tenant.
     *
     * Contract:
     *   - User is authenticated (via `actingAs`).
     *   - User is a member of the tenant they request.
     *   - The diagnostic endpoint reports `tenant_id` and `is_member: true`.
     *
     * Asserts:
     *   - HTTP 200 OK.
     *   - `data.tenant_id` equals the tenant's ID.
     *   - `data.is_member` is `true`.
     *   - `data.resolution_source` is `'header'` (since we sent X-Tenant-ID).
     *
     * @return void
     */
    #[Test]
    public function member_can_access_their_tenant(): void
    {
        // Arrange: create a tenant and a user who is a member.
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user   = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id);

        // Authenticate as the user.
        $this->actingAs($user, 'sanctum');

        // Act: request the diagnostic endpoint with the tenant header.
        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/tenant-context');

        // Assert: the tenant is reported and membership is confirmed.
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.tenant_id', $tenant->id);
        $response->assertJsonPath('data.is_member', true);
        $response->assertJsonPath('data.user_id', $user->id);
        $response->assertJsonPath('data.resolution_source', 'header');
    }

    // =========================================================================
    // Test 2: Non-Member Cannot Access Tenant
    // =========================================================================

    /**
     * Test that a non-member user does not gain tenant access.
     *
     * Contract:
     *   - User is authenticated but is NOT a member of the requested tenant.
     *   - The diagnostic endpoint still reports the resolved tenant (because
     *     the user supplied the header) but flags `is_member: false`.
     *
     * Security rationale:
     *   - The user already knows the tenant ID (they sent it).
     *   - No cross-tenant data is exposed.
     *   - Downstream routes (files, etc.) will reject with 403.
     *
     * Asserts:
     *   - HTTP 200 OK (never 403 on the diagnostic endpoint).
     *   - `data.is_member` is `false`.
     *   - `data.tenant_id` equals the requested (but not authorized) tenant.
     *
     * @return void
     */
    #[Test]
    public function non_member_cannot_access_tenant(): void
    {
        // Arrange: two tenants, user is member of A only.
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenantA->id); // member of A, NOT B.

        $this->actingAs($user, 'sanctum');

        // Act: request tenant B via header (user is NOT a member).
        $response = $this->withHeader('X-Tenant-ID', (string) $tenantB->id)
            ->getJson('/api/tenant-context');

        // Assert: diagnostic endpoint still returns 200 with is_member=false.
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.tenant_id', $tenantB->id);
        $response->assertJsonPath('data.is_member', false);
        $response->assertJsonPath('data.user_id', $user->id);
    }

    // =========================================================================
    // Test 3: User Without Any Membership
    // =========================================================================

    /**
     * Test that a user with zero tenant memberships gets is_member=false.
     *
     * Contract:
     *   - User is authenticated but belongs to NO tenants at all.
     *   - Requesting a tenant still returns 200 with `is_member: false`.
     *
     * @return void
     */
    #[Test]
    public function user_without_membership_cannot_access_tenant(): void
    {
        // Arrange: a tenant and a user with NO memberships.
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user   = User::factory()->create(['is_active' => true]);
        // NOTE: deliberately no `$user->tenants()->attach(...)`.

        $this->actingAs($user, 'sanctum');

        // Act
        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/tenant-context');

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_member', false);
        $response->assertJsonPath('data.user_id', $user->id);
    }

    // =========================================================================
    // Test 4: Public Endpoints Accessible Without Tenant
    // =========================================================================

    /**
     * Test that public endpoints are accessible without tenant context.
     *
     * The `/api/health` endpoint is intentionally public and must never
     * require tenant context or authentication.
     *
     * The response is wrapped in the canonical MASES envelope; the health
     * payload lives under `data`.
     *
     * @return void
     */
    #[Test]
    public function public_endpoints_accessible_without_tenant(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonStructure([
            'success',
            'data' => [
                'status',
                'timestamp',
                'version',
                'environment',
            ],
        ]);
    }

    // =========================================================================
    // Test 5: Missing Tenant Context Handled Gracefully
    // =========================================================================

    /**
     * Test that missing tenant context returns a clean null, not an error.
     *
     * Contract:
     *   - Authenticated user, but NO tenant header / input / user default.
     *   - Endpoint returns 200 with `tenant_id: null` and `is_member: false`.
     *   - `resolution_source` is `'none'`.
     *
     * This is the most important behaviour of the diagnostic endpoint:
     * a missing tenant must NEVER produce a 400 error on this route.
     *
     * @return void
     */
    #[Test]
    public function missing_tenant_context_handled(): void
    {
        // Arrange: user with no default tenant.
        $user = User::factory()->create([
            'is_active'          => true,
            'default_tenant_id'  => null,
        ]);

        $this->actingAs($user, 'sanctum');

        // Act: request without any tenant hint.
        $response = $this->getJson('/api/tenant-context');

        // Assert: 200 with null tenant context.
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.tenant_id', null);
        $response->assertJsonPath('data.is_member', false);
        $response->assertJsonPath('data.resolution_source', 'none');
    }

    // =========================================================================
    // Test 6: Tenant-Required Route Without Tenant Context
    // =========================================================================

    /**
     * Test that a route requiring tenant context fails when tenant is missing.
     *
     * This test registers a local route guarded by `tenant.membership` (the
     * default middleware, which enforces `requireTenant = true`) and asserts
     * that a request without any tenant hint is rejected with a 400 and
     * the `TENANT_REQUIRED` error code.
     *
     * This replaces the previously-skipped test — the behavior is now fully
     * verified here, without needing to modify production routes.
     *
     * @return void
     */
    #[Test]
    public function tenant_required_route_fails_without_tenant(): void
    {
        // Register a route that requires tenant.membership with default
        // settings (i.e., a tenant IS required).
        $this->app['router']->get('/_test/tenant-required', static function () {
            return response()->json([
                'success'   => true,
                'tenant_id' => mases_tenant()?->id,
            ]);
        })->middleware(['auth:sanctum', 'tenant.membership']);

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user, 'sanctum');

        // Act: no tenant header, default test host doesn't resolve a tenant.
        $response = $this->getJson('/_test/tenant-required');

        // Assert: 400 TENANT_REQUIRED.
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error'   => [
                'code' => 'TENANT_REQUIRED',
            ],
        ]);
    }

    // =========================================================================
    // Test 7: Subdomain Resolution (Delegated)
    // =========================================================================

    /**
     * Test that tenant context is resolved from subdomain.
     *
     * Subdomain resolution is already covered by the unit test suite
     * (`TenantResolverTest`). Repeating it here would require overriding
     * the HTTP_HOST in a way that interferes with Laravel's `RefreshDatabase`
     * and Sanctum's `actingAs`, so we defer to the unit tests.
     *
     * @return void
     */
    #[Test]
    public function tenant_context_resolved_from_subdomain(): void
    {
        $this->markTestSkipped(
            'Subdomain resolution is covered by TenantResolverTest (unit).'
        );
    }

    // =========================================================================
    // Tear Down
    // =========================================================================

    /**
     * Clean up Mockery and any container bindings between tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}