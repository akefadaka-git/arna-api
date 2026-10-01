<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * TenantRequiredRouteTest
 *
 * Priority 3 of Phase 1.5 — dedicated coverage for the `tenant.membership`
 * middleware's "require tenant" behavior.
 *
 * This test file replaces the two previously skipped tests in
 * TenantIsolationTest by exercising real routes guarded by
 * `tenant.membership` with both the default (`requireTenant = true`) and
 * the relaxed (`tenant.membership:false`) configurations.
 *
 * Coverage:
 *   1. `tenant.membership` (default = true):
 *      - No tenant context + authenticated user → 400 TENANT_REQUIRED.
 *      - Valid tenant + member → 200.
 *      - Valid tenant + non-member → 403 TENANT_ACCESS_DENIED.
 *      - Invalid tenant ID → 400 TENANT_REQUIRED (resolver returns null).
 *
 *   2. `tenant.membership:false`:
 *      - No tenant context + authenticated user → 200 (skipped).
 *      - Valid tenant + member → 200.
 *      - Valid tenant + non-member → 403 (membership still enforced).
 *
 *   3. Unauthenticated request on a tenant-required route → 401 AUTH_REQUIRED.
 *
 * All assertions are on the public HTTP response; no internal service is
 * touched directly, so the tests reflect the actual behavior a client sees.
 *
 * @package Tests\Feature
 */
final class TenantRequiredRouteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Base URI for the tenant-required test route.
     */
    private const REQUIRED_ROUTE = '/_test/tenant-required';

    /**
     * Base URI for the tenant-optional test route.
     */
    private const OPTIONAL_ROUTE = '/_test/tenant-optional';

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the resolver is the standard request resolver and
        // tenant isolation is on so the middleware behaves as in production.
        Config::set('mases.tenant.isolation_enabled', true);
        Config::set(
            'mases.tenant.context_resolver',
            RequestTenantResolver::class
        );

        // Route guarded by tenant.membership with the default (require = true).
        $this->app['router']->get(self::REQUIRED_ROUTE, static function () {
            $tenant = mases_tenant();

            return response()->json([
                'success'     => true,
                'tenant_id'   => $tenant?->id,
                'tenant_slug' => $tenant?->slug,
            ]);
        })->middleware(['auth:sanctum', 'tenant.membership']);

        // Route guarded by tenant.membership with the relaxed (require = false).
        $this->app['router']->get(self::OPTIONAL_ROUTE, static function () {
            $tenant = mases_tenant();

            return response()->json([
                'success'     => true,
                'tenant_id'   => $tenant?->id,
                'tenant_slug' => $tenant?->slug,
            ]);
        })->middleware(['auth:sanctum', 'tenant.membership:false']);
    }

    // =========================================================================
    // tenant.membership (requireTenant = true, the default)
    // =========================================================================
    #[Test]
    public function required_route_returns_400_when_no_tenant_context_is_present(): void
    {
        [$tenant, $user] = $this->makeMemberUser();

        $this->actingAs($user, 'sanctum');

        // No X-Tenant-ID header, and the default test host ("localhost")
        // does not resolve to any tenant.
        $response = $this->getJson(self::REQUIRED_ROUTE);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error'   => [
                'code' => 'TENANT_REQUIRED',
            ],
        ]);
    }

    #[Test]
    public function required_route_returns_200_for_a_member_of_the_resolved_tenant(): void
    {
        [$tenant, $user] = $this->makeMemberUser();

        $this->actingAs($user, 'sanctum');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson(self::REQUIRED_ROUTE);

        $response->assertStatus(200);
        $response->assertJson([
            'success'   => true,
            'tenant_id' => $tenant->id,
        ]);
    }

    #[Test]
    public function required_route_returns_403_for_a_non_member_of_the_resolved_tenant(): void
    {
        // User belongs to tenant A.
        [$tenantA, $user] = $this->makeMemberUser();

        // A separate tenant B exists but the user is not a member of it.
        $tenantB = Tenant::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenantB->id)
            ->getJson(self::REQUIRED_ROUTE);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error'   => [
                'code' => 'TENANT_ACCESS_DENIED',
            ],
        ]);
    }

    #[Test]
    public function required_route_returns_400_when_the_resolved_tenant_id_is_invalid(): void
    {
        [, $user] = $this->makeMemberUser();

        $this->actingAs($user, 'sanctum');

        // A tenant ID that does not exist. The resolver returns it as-is,
        // TenantContextService fails to load it, the middleware clears the
        // tenant, and — because a tenant is required — the request is
        // rejected as TENANT_REQUIRED.
        $response = $this->withHeader('X-Tenant-ID', '999999999')
            ->getJson(self::REQUIRED_ROUTE);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error'   => [
                'code' => 'TENANT_REQUIRED',
            ],
        ]);
    }

    // =========================================================================
    // tenant.membership:false (requireTenant = false)
    // =========================================================================
    #[Test]
    public function optional_route_returns_200_when_no_tenant_context_is_present(): void
    {
        [, $user] = $this->makeMemberUser();

        $this->actingAs($user, 'sanctum');

        // No tenant header — but the route allows it.
        $response = $this->getJson(self::OPTIONAL_ROUTE);

        $response->assertStatus(200);
        $response->assertJson([
            'success'   => true,
            'tenant_id' => null,
        ]);
    }

    #[Test]
    public function optional_route_returns_200_for_a_member_of_the_resolved_tenant(): void
    {
        [$tenant, $user] = $this->makeMemberUser();

        $this->actingAs($user, 'sanctum');

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson(self::OPTIONAL_ROUTE);

        $response->assertStatus(200);
        $response->assertJson([
            'success'   => true,
            'tenant_id' => $tenant->id,
        ]);
    }

    #[Test]
    public function optional_route_still_returns_403_for_a_non_member_when_a_tenant_is_present(): void
    {
        // User belongs to tenant A.
        [$tenantA, $user] = $this->makeMemberUser();

        // Tenant B exists but the user is not a member of it.
        $tenantB = Tenant::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum');

        // Even with :false, if a tenant IS resolved, membership is checked.
        $response = $this->withHeader('X-Tenant-ID', (string) $tenantB->id)
            ->getJson(self::OPTIONAL_ROUTE);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error'   => [
                'code' => 'TENANT_ACCESS_DENIED',
            ],
        ]);
    }

    // =========================================================================
    // Authentication requirement
    // =========================================================================
    #[Test]
    public function unauthenticated_request_is_rejected_with_401(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson(self::REQUIRED_ROUTE);

        // Sanctum authentication runs first (auth:sanctum middleware) and
        // rejects the request with 401 before tenant.membership is reached.
        $response->assertStatus(401);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a tenant and a user who is a member of it.
     *
     * @return array{0: Tenant, 1: User}
     */
    private function makeMemberUser(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id);

        return [$tenant, $user];
    }
}