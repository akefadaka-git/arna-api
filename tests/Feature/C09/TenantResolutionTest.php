<?php

declare(strict_types=1);

namespace Tests\Feature\C09;

use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * TenantResolutionTest
 *
 * =============================================================================
 * C-09 REGRESSION SUITE
 * =============================================================================
 *
 * Regression coverage for the C-09 tenant-context correction. Before the
 * fix, `mases_tenant_id()` gave priority to the request body/query
 * `tenant_id` value over the `X-Tenant-ID` header — even though the
 * middleware pipeline (`TenantContextMiddleware` + `TenantMembershipMiddleware`)
 * had already verified membership against the header-derived tenant.
 *
 * The consequence: an authenticated user who is a member of tenant A
 * could send `?tenant_id=B` (or a body containing `tenant_id: B`) and
 * cause any downstream consumer of `mases_tenant_id()` — including
 * authorization checks, audit writes, security-event writes, and every
 * domain controller's data scoping — to operate against tenant B. That
 * is a cross-tenant data-exposure vulnerability.
 *
 * After the fix, in HTTP context `mases_tenant_id()` reads tenant context
 * exclusively from `TenantContextService`, which the middleware pipeline
 * populates. Request body and query `tenant_id` is ordinary input; it is
 * never security tenant context.
 *
 * =============================================================================
 * TEST SUBJECT
 * =============================================================================
 * `GET /api/shareholders`
 *
 * A tenant-scoped endpoint that queries the `shareholders` table by
 * `tenant_id`. If a request reaches the controller with a body/query
 * `tenant_id` that overrode the header, the returned list would contain
 * another tenant's rows. This makes the endpoint a direct witness of the
 * defect and of the fix.
 *
 * =============================================================================
 * WHAT EACH TEST PROVES
 * =============================================================================
 *   T1 — Body cannot override header.
 *        Request carries header A + body B. Response contains A's data
 *        only. Body B is silently ignored.
 *
 *   T2 — Body cannot elevate cross-tenant access.
 *        Same setup as T1. Response does NOT contain B's data. The
 *        endpoint returns 200 with A's list, not 403, not B's list.
 *        (The user is a legitimate member of A; the request is
 *        legitimate against A; the fix ensures "against A" is what
 *        actually happens.)
 *
 *   T3 — Body cannot substitute for a missing header.
 *        No header, body carries A. The tenant-required route rejects
 *        with 400 TENANT_REQUIRED. The middleware never saw a header
 *        and the resolver returned null; the body is not a resolver
 *        input.
 *
 *   T4 — Security events record the verified tenant.
 *        Login is performed with header A + body B (user is member of
 *        both). The persisted `security_events` row's `tenant_id` is A,
 *        not B. This directly exercises the file-2 change
 *        (`SecurityEventService` body fallback removal) end-to-end.
 *
 *   T5 — HTTP null stays null; no fallback to user default.
 *        Request has neither header nor body. The endpoint is one
 *        without tenant.membership (the diagnostic `GET /api/tenant-context`).
 *        Response reports `tenant_id: null`, even though the user has a
 *        default tenant. This proves the C-09 correction's key rule:
 *        HTTP null does NOT fall back to `$user->default_tenant_id`.
 *
 *   T5b — Subdomain-only resolution still works when the host matches.
 *        Verified only if the environment can simulate a subdomain host.
 *        Skipped by default — see the test body.
 *
 *   T6 — Explicit `$tenantId` must match trusted tenant context.
 *        Explicit scope is accepted only when it matches the server-side
 *        context; a concrete ID is not authority to cross tenants.
 *
 * =============================================================================
 * WHAT THESE TESTS DO NOT COVER
 * =============================================================================
 *   · Non-HTTP (console/queue/scheduler) behavior — covered by the
 *     existing suite where relevant; the C-09 change preserves it
 *     unchanged.
 *   · Frontend behavior — deferred to Phase 0-B.
 *   · Load or concurrency behavior — out of scope.
 *
 * @package Tests\Feature\C09
 */
final class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // T1 — Body cannot override header (the core C-09 test)
    // =========================================================================

    /**
     * The core C-09 scenario.
     *
     * Two tenants, user is a member of A only, both tenants have one
     * shareholder. Request: header = A, query = ?tenant_id=B. Response
     * must reflect A's shareholders, not B's.
     *
     * Fails on the pre-C-09 codebase: `mases_tenant_id()` returns B, the
     * endpoint's query scopes to B, and the response contains B's row.
     * Passes on the C-09-corrected codebase: `mases_tenant_id()` returns
     * A, the query scopes to A.
     */
    public function test_body_tenant_id_cannot_override_header(): void
    {
        $scenario = $this->scenarioTwoTenants();
        Sanctum::actingAs($scenario['user'], ['*']);

        $response = $this
            ->withHeaders(['X-Tenant-ID' => (string) $scenario['tenantA']->id])
            ->getJson('/api/shareholders?tenant_id=' . $scenario['tenantB']->id);

        $response->assertOk();

        $json = $response->json();
        $this->assertTrue(
            $this->jsonContainsId($json, $scenario['shareholderA']->id),
            'Response must contain tenant A\'s shareholder.'
        );
        $this->assertFalse(
            $this->jsonContainsId($json, $scenario['shareholderB']->id),
            'Response must NOT contain tenant B\'s shareholder; body tenant_id was ignored.'
        );
    }

    // =========================================================================
    // T2 — Body cannot elevate cross-tenant access
    // =========================================================================

    /**
     * The same scenario as T1, framed as a cross-tenant-access check.
     *
     * The test asserts the response carries only A's data. It is
     * deliberately a separate test from T1 because the concerns differ:
     * T1 checks "which tenant won", T2 checks "no data from another tenant
     * leaked". A future regression could conceivably break T2 without
     * breaking T1 (e.g. if a refactor returned the union of two tenants).
     */
    public function test_body_tenant_id_cannot_elevate_cross_tenant_access(): void
    {
        $scenario = $this->scenarioTwoTenants();
        Sanctum::actingAs($scenario['user'], ['*']);

        $response = $this
            ->withHeaders(['X-Tenant-ID' => (string) $scenario['tenantA']->id])
            ->getJson('/api/shareholders?tenant_id=' . $scenario['tenantB']->id);

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString(
            'BobInB',
            $content,
            'Tenant B\'s shareholder data must not appear in the response.'
        );
    }

    // =========================================================================
    // T3 — Body cannot substitute for a missing header
    // =========================================================================

    /**
     * A tenant-required route demands a middleware-resolved tenant.
     *
     * No header is sent; body/query carries tenant A. Under the pre-C-09
     * resolver, the body would have supplied the tenant and the request
     * would proceed. Under the C-09 model, the middleware does not read
     * the body; the resolver returns null; `TenantMembershipMiddleware`
     * rejects with 400 TENANT_REQUIRED.
     */
    public function test_body_tenant_id_cannot_substitute_for_missing_header(): void
    {
        $scenario = $this->scenarioTwoTenants();
        Sanctum::actingAs($scenario['user'], ['*']);

        $response = $this->getJson(
            '/api/shareholders?tenant_id=' . $scenario['tenantA']->id
        );

        // 400 TENANT_REQUIRED is the expected rejection for a tenant-required
        // route when no tenant has been resolved. If the codebase produces a
        // different status via TenantException, adjust the assertion to
        // `assertStatus(400)` and to the matching error code.
        $response->assertStatus(400);
    }

    // =========================================================================
    // T4 — Security events carry the verified tenant
    // =========================================================================

    /**
     * Login with header A + body B. User is a member of both.
     *
     * The security event persisted by `AuthenticationService::login()`
     * must carry tenant A — the tenant the middleware resolved — not B,
     * the tenant the body requested for the login flow.
     *
     * This test directly exercises the file-2 change: the removal of
     * `Request::has('tenant_id')` from `SecurityEventService::log()`.
     */
    public function test_security_event_carries_verified_tenant(): void
    {
        $tenantA = Tenant::create([
            'slug' => 'tenant-a-' . uniqid(),
            'name_en' => 'Tenant A',
            'is_active' => true,
        ]);
        $tenantB = Tenant::create([
            'slug' => 'tenant-b-' . uniqid(),
            'name_en' => 'Tenant B',
            'is_active' => true,
        ]);

        $user = User::create([
            'email' => 'c09-t4-' . uniqid() . '@example.test',
            'password' => bcrypt('password123'),
            'first_name_en' => 'Test',
            'is_active' => true,
            'default_tenant_id' => $tenantA->id,
            'locale' => 'am',
        ]);

        // The user is a legitimate member of both tenants so the login
        // service accepts the body tenant_id (B) as a valid attempt
        // target and does not reject.
        $user->tenants()->attach($tenantA->id);
        $user->tenants()->attach($tenantB->id);

        $response = $this
            ->withHeaders(['X-Tenant-ID' => (string) $tenantA->id])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password123',
                'tenant_id' => $tenantB->id,
            ]);

        $response->assertOk();

        $event = DB::table('security_events')
            ->where('event_type', 'login.successful')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'A login.successful security event must have been recorded.');
        $this->assertEquals(
            $tenantA->id,
            (int) $event->tenant_id,
            'The security event tenant must be the header-verified tenant A, not the body tenant B.'
        );
    }

    // =========================================================================
    // T5 — HTTP null stays null (no fallback to user default)
    // =========================================================================

    /**
     * HTTP request with no header and no body. User has a default tenant
     * A. The C-09 rule: `mases_tenant_id()` must return null in HTTP
     * context when the middleware resolved no tenant. It must NOT fall
     * back to `$user->default_tenant_id`.
     *
     * The diagnostic endpoint `GET /api/tenant-context` is used because
     * it runs behind `auth:sanctum` only (not `tenant.membership`), so it
     * is reachable without a resolved tenant and reports the outcome.
     */
    public function test_http_null_stays_null_even_with_user_default_tenant(): void
    {
        $tenantA = Tenant::create([
            'slug' => 'tenant-a-' . uniqid(),
            'name_en' => 'Tenant A',
            'is_active' => true,
        ]);

        $user = User::create([
            'email' => 'c09-t5-' . uniqid() . '@example.test',
            'password' => bcrypt('password123'),
            'first_name_en' => 'Test',
            'is_active' => true,
            'default_tenant_id' => $tenantA->id,
            'locale' => 'am',
        ]);

        $user->tenants()->attach($tenantA->id);

        Sanctum::actingAs($user, ['*']);

        // No X-Tenant-ID header, no body tenant_id. The global
        // TenantContextMiddleware runs but resolves nothing (the test
        // host is not a subdomain of the platform's registrable domain).
        $response = $this->getJson('/api/tenant-context');

        $response->assertOk();
        $response->assertJsonPath('data.tenant_id', null);
    }

    // =========================================================================
    // T5b — Subdomain resolution still works (skipped unless host supported)
    // =========================================================================

    /**
     * When the host carries a non-reserved subdomain of the platform's
     * registrable domain, `RequestTenantResolver` returns that subdomain
     * and the middleware sets the tenant context to it.
     *
     * Simulating a subdomain host in phpunit requires the test HTTP
     * client to accept an arbitrary host — which the default test harness
     * does through `->withServerVariables(['HTTP_HOST' => ...])`.
     *
     * This test is marked skipped by default because the resolver's
     * behaviour is already covered by `tests/Unit/TenantResolverTest.php`
     * (per the Phase 1 gate report). It is retained here as a placeholder
     * for a future harness where subdomain integration is warranted.
     */
    public function test_subdomain_resolution_still_works(): void
    {
        $this->markTestSkipped(
            'Subdomain resolution is covered at the unit level in ' .
            'tests/Unit/TenantResolverTest.php. Re-enable if integration-level ' .
            'coverage becomes necessary.'
        );
    }

    // =========================================================================
    // T6 — Explicit tenant argument to AuthorizationService is honored
    // =========================================================================

    /**
     * `AuthorizationService::hasPermission()` accepts an explicit
     * `$tenantId` argument that overrides the request context. This is
     * the contract that console commands and queued jobs depend on.
     *
     * The test proves the C-09 correction did not weaken this contract:
     * a caller that supplies a concrete tenant gets a check scoped to
     * that tenant, regardless of what the middleware resolved.
     */
    public function test_tenant_lookup_failure_log_does_not_disclose_tenant_inventory(): void
    {
        $context = app(\MaaleArna\MASES\Core\Tenancy\Services\TenantContextService::class);

        Log::shouldReceive('warning')
            ->once()
            ->with('Tenant not found or inactive', \Mockery::on(
                static function (array $payload): bool {
                    foreach (['tenant_ids', 'is_active_values', 'total_tenants'] as $forbiddenKey) {
                        if (array_key_exists($forbiddenKey, $payload)) {
                            return false;
                        }
                    }

                    return array_key_exists('tenant_id', $payload)
                        && array_key_exists('connection', $payload)
                        && array_key_exists('database', $payload);
                }
            ));

        $this->assertNull($context->setTenantById('999999999'));
        $this->assertFalse($context->hasTenant());
    }

    public function test_explicit_tenant_argument_must_match_trusted_context(): void
    {
        $scenario = $this->scenarioTwoTenants();
        $user = $scenario['user'];
        $tenantA = $scenario['tenantA'];
        $tenantB = $scenario['tenantB'];

        $context = app(\MaaleArna\MASES\Core\Tenancy\Services\TenantContextService::class);
        $context->setTenantById($tenantA->id);

        try {
            $authorization = app(AuthorizationService::class);

            $this->assertTrue(
                $authorization->hasPermission('shareholder.view', $user, $tenantA->id),
                'The explicit tenant must be honoured when it matches trusted context.'
            );
            $this->assertFalse(
                $authorization->hasPermission('shareholder.view', $user, $tenantB->id),
                'A global role/permission grant must not permit switching to another tenant.'
            );
        } finally {
            $context->clearTenant();
        }
    }

    // =========================================================================
    // Scenario builder
    // =========================================================================

    /**
     * Build the two-tenant scenario used by T1, T2, T3, and T6.
     *
     * @return array{
     *     user: User,
     *     tenantA: Tenant,
     *     tenantB: Tenant,
     *     shareholderA: Shareholder,
     *     shareholderB: Shareholder,
     * }
     */
    private function scenarioTwoTenants(): array
    {
        $tenantA = Tenant::create([
            'slug' => 'tenant-a-' . uniqid(),
            'name_en' => 'Tenant A',
            'is_active' => true,
        ]);
        $tenantB = Tenant::create([
            'slug' => 'tenant-b-' . uniqid(),
            'name_en' => 'Tenant B',
            'is_active' => true,
        ]);

        $user = User::create([
            'email' => 'c09-scenario-' . uniqid() . '@example.test',
            'password' => bcrypt('password123'),
            'first_name_en' => 'ScenarioUser',
            'is_active' => true,
            'default_tenant_id' => $tenantA->id,
            'locale' => 'am',
        ]);

        // The user is a member of A only.
        $user->tenants()->attach($tenantA->id);

        // Grant the `shareholder.view` permission via a platform-scoped
        // role. The role has `tenant_id = null`, so it applies in every
        // tenant the user is scoped to. This isolates the test from the
        // permission-grant path and keeps the assertion focused on the
        // tenant-resolution behaviour the C-09 fix changes.
        $permission = Permission::create([
            'slug' => 'shareholder.view',
            'name_en' => 'View shareholders',
            'name_am' => 'ባለአክሲዮኖችን ማየት',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);

        $role = Role::create([
            'slug' => 'shareholder-viewer-' . uniqid(),
            'name_en' => 'Shareholder Viewer',
            'name_am' => 'የባለአክሲዮን ተመልካች',
            'scope' => 'platform',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $role->permissions()->attach($permission->id, ['assigned_at' => now()]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        // One shareholder in each tenant, distinguished by a sentinel
        // string in `first_name_en` so the response content can be
        // searched unambiguously.
        $shareholderA = Shareholder::create([
            'tenant_id' => $tenantA->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'AliceInA',
            'first_name_am' => null,
            'father_name_en' => 'Sentinel',
            'grandfather_name_en' => 'Sentinel',
            'is_active' => true,
        ]);
        $shareholderB = Shareholder::create([
            'tenant_id' => $tenantB->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'BobInB',
            'first_name_am' => null,
            'father_name_en' => 'Sentinel',
            'grandfather_name_en' => 'Sentinel',
            'is_active' => true,
        ]);

        return [
            'user' => $user,
            'tenantA' => $tenantA,
            'tenantB' => $tenantB,
            'shareholderA' => $shareholderA,
            'shareholderB' => $shareholderB,
        ];
    }

    // =========================================================================
    // Assertion helpers
    // =========================================================================

    /**
     * Recursively search a decoded JSON structure for a given `id` value.
     *
     * Used instead of `assertJsonFragment` / `assertJsonMissing` because
     * the exact response envelope shape (paginated vs. wrapped vs. bare)
     * is not guaranteed to be stable across the endpoint's evolution, and
     * a manual search is robust to any of those shapes.
     *
     * @param mixed  $node  A decoded JSON value (array, scalar, null).
     * @param int    $id    The id to look for.
     * @param string $key   The key that must hold the id.
     *
     * @return bool
     */
    private function jsonContainsId(mixed $node, int $id, string $key = 'id'): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (isset($node[$key]) && (int) $node[$key] === $id) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && $this->jsonContainsId($value, $id, $key)) {
                return true;
            }
        }

        return false;
    }
}