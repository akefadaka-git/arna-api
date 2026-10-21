<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 17 of 18 (revised)
 * =============================================================================
 *
 * File:            Tests/Feature/GovernanceBodyTest.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (feature test)
 * Delivery type:   New file — REVISED from an earlier draft that was
 *                  delivered to the wrong path.
 *
 * Revision note:
 *   An earlier draft of this file was delivered to
 *   `tests/Feature/Domains/Governance/GovernanceBodyTest.php` with the
 *   namespace `Tests\Feature\Domains\Governance`. That path and
 *   namespace do not match the platform's actual test convention.
 *
 *   The platform's convention — established by the Shareholder
 *   domain's test suite (2A.46–2A.49) and used identically by Wallet
 *   and Loan — is:
 *
 *     · Tests live INSIDE the domain package:
 *         mases_core/src/Domains/<Domain>/Tests/{Unit,Feature}/
 *
 *     · The namespace matches the physical path:
 *         MaaleArna\MASES\Core\Domains\<Domain>\Tests\Feature
 *
 *     · The base class is `Tests\TestCase` (top-level), not a
 *       domain-local base.
 *
 *     · Each domain's test directories are enumerated explicitly in
 *       `phpunit.xml` — PHPUnit does not support `**` wildcards in
 *       `<directory>` paths (phpunit#5359), so an unlisted directory
 *       is silently skipped by `php artisan test`.
 *
 *   This revision corrects the path and namespace. The class body is
 *   substantively the same as the earlier draft — the same 13 test
 *   methods covering authentication, tenant context, RBAC, tenant
 *   isolation, shape validation, service invariants, and audit — but
 *   adapted to the platform's conventions in five ways:
 *
 *     1. Uses `Sanctum::actingAs($user, ['*'])` rather than
 *        `$this->actingAs($user)` — the domain's routes are behind
 *        `auth:sanctum` and the Sanctum test helper is the correct
 *        way to authenticate for them. `actingAs()` uses the `web`
 *        guard, which does not satisfy `auth:sanctum`.
 *
 *     2. Uses `route('api.governance.bodies.index')` (and the three
 *        sibling names) rather than hardcoded URL strings. This
 *        lets the tests survive a route-prefix config change, and
 *        it verifies the route names are correct as a side effect.
 *
 *     3. Binds the tenant into the service container via a
 *        `bindTenantContext()` helper — the same helper the
 *        Shareholder test declares. This ensures that any code path
 *        that reads the tenant via `mases_tenant()` (rather than
 *        `mases_tenant_id()`) sees the same tenant the fixtures
 *        were written against.
 *
 *     4. Includes `gc_collect_cycles()` at both ends of the test
 *        lifecycle — the same memory hygiene the Shareholder suite
 *        uses to stay inside the Plesk CLI PHP memory limit.
 *
 *     5. Names the permission-creation helper
 *        `createGovernancePermission()` (not `createPermission()`,
 *        which would collide with the base `Tests\TestCase`).
 *
 * After:
 *   The file is on disk at the platform's convention path. Combined
 *   with the phpunit.xml amendment (G2a file 18 of 18), the test
 *   runs under `php artisan test --filter=GovernanceBodyTest` on any
 *   environment with the Plesk PHP Command runner or an equivalent
 *   mechanism.
 *
 *   What the test covers — 13 methods across seven concerns:
 *
 *     Authentication   — no token → 401.
 *     Tenant context   — no X-Tenant-ID → non-success.
 *     RBAC             — plain user cannot list or create.
 *     Happy path       — index / show / store / update succeed.
 *     Tenant isolation — cross-tenant id → 404 (not 403).
 *     Validation       — duplicate code, bad type, no name, bad
 *                        date range, body-type change → 422.
 *     Audit            — create and update each write exactly one
 *                        audit row.
 *
 *   What the test deliberately does NOT cover:
 *
 *     · The `RendersLocalizedDates` trait's exact output. The trait
 *       is a thin wrapper over `LocalizationService::formatEthiopianDateMedium()`,
 *       which has its own Core coverage. The resource's use of the
 *       trait is verified by the `assertJson` calls that check the
 *       `_display` siblings exist; the exact string format is not
 *       asserted.
 *
 *     · The composite-sync algorithm in `GovernanceRolesSeeder`.
 *       That seeder is G1 scope; its correctness is verified by
 *       direct inspection of the pivot after running the seeder,
 *       not by a PHPUnit assertion here.
 *
 *     · The `update` route's PATCH-vs-PUT distinction. Both verbs
 *       reach the same controller action with the same
 *       `sometimes` request rules; the test exercises PATCH only.
 *       A PUT request would behave identically.
 *
 * =============================================================================
 * A3 CANDIDATE — RP-06 audit integrity regression guard
 * =============================================================================
 * Two methods are added at the end of the Audit section:
 *
 *   test_update_audit_row_records_exact_before_and_after_values()
 *   test_status_update_via_update_endpoint_records_exact_before_and_after_values()
 *
 * They assert (F1) exact count of matching audit rows via precise
 * filters, (F2) that a PATCH that flips `is_active` is described as a
 * status update — the API exposes no dedicated deactivate route — and
 * (F3) the exact expected before/after values, read through the
 * AuditLog model's `old_values` / `new_values` array casts (F4).
 *
 * Nothing else in the file is changed.
 *
 * =============================================================================
 * Test infrastructure assumptions
 * =============================================================================
 * The class assumes the platform's `phpunit.xml` (amended by G2a
 * file 18 of 18) declares a `Governance Feature` suite pointing at
 * `mases_core/src/Domains/Governance/Tests/Feature`. Without that
 * amendment, the test class is on disk but is not discovered by
 * `php artisan test`.
 *
 * The class also assumes `RefreshDatabase` migrates a fresh SQLite
 * in-memory schema for each test (per the existing `phpunit.xml`
 * `<env name="DB_CONNECTION" value="sqlite" force="true"/>` and
 * `<env name="DB_DATABASE" value=":memory:" force="true"/>` lines).
 * Because the schema is fresh, the test creates exactly the fixtures
 * it needs — a tenant, a super-admin user with the two governance
 * permissions, and a plain user without them — and does not rely on
 * any seeder.
 *
 * =============================================================================
 * Running the tests
 * =============================================================================
 * Development environment with SSH:
 *
 *     php artisan test --filter=GovernanceBodyTest
 *
 * Plesk shared hosting without SSH — three options:
 *
 *   1. Plesk's PHP Command runner (Tools & Settings → PHP → Commands),
 *      if your plan includes it. Run:
 *          php artisan test --filter=GovernanceBodyTest
 *
 *   2. Plesk Scheduled Tasks UI — configure a one-off task, run it,
 *      read the email output, then disable the task.
 *
 *   3. Manual HTTP smoke tests against the four endpoints per the
 *      verification block of G2a file 16 of 18.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Test methods:              13
 * Endpoints exercised:       4
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * GovernanceBodyTest
 *
 * Feature tests for the GovernanceBody vertical slice — the four
 * endpoints delivered by G2a.
 *
 * See the file-level change-verification block for the test's scope,
 * its revision history, its assumptions about the platform's testing
 * infrastructure, and its note on running without SSH.
 *
 * =============================================================================
 * Fixture topology
 * =============================================================================
 *   tenantA         — the caller's tenant. The admin is a member.
 *   tenantB         — the foreign tenant. The admin is NOT a member.
 *   admin           — holds governance.body.view and
 *                     governance.body.manage in tenantA.
 *   plainUser       — holds no governance permissions.
 *   bodyA           — a body in tenantA (used for happy-path reads
 *                     and updates).
 *   bodyB           — a body in tenantB (used to verify the tenant
 *                     boundary rejects cross-tenant access).
 *
 * =============================================================================
 * Raw-body scan policy
 * =============================================================================
 * Where a test scans the raw response body for evidence of
 * cross-tenant leakage, the scan target is a semantic, unambiguously
 * scoped marker — the body's Amharic code or name — not a numeric
 * id. Numeric ids collide with timestamps, UUIDs, and exception line
 * numbers, producing false positives. This mirrors the Shareholder
 * domain's `TenantIsolationTest` v1.2.0, which uses the same policy.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of every test, following
 * the Shareholder suite's pattern. This keeps the Plesk CLI PHP
 * memory limit from being exhausted by accumulated fixture
 * references across the 13 test methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Tests\Feature
 * @version 1.0.0
 */
final class GovernanceBodyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The tenant all happy-path tests operate within.
     */
    private Tenant $tenantA;

    /**
     * A second tenant, used only to verify the tenant boundary.
     */
    private Tenant $tenantB;

    /**
     * A user holding the two governance-body permissions in
     * tenantA.
     */
    private User $admin;

    /**
     * A user belonging to tenantA but holding no governance
     * permissions.
     */
    private User $plainUser;

    /**
     * A governance body in tenantA.
     */
    private GovernanceBody $bodyA;

    /**
     * A governance body in tenantB. Its Amharic name
     * (`የተከለከለ አካል`) is used as the semantic leak marker in
     * raw-body scans — it is unambiguously tied to tenantB and
     * cannot collide with timestamps, UUIDs, or exception line
     * numbers.
     */
    private GovernanceBody $bodyB;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Set up the test environment.
     *
     * Runs before each test method. Creates exactly the fixtures
     * each test needs — no seeders are invoked, because the schema
     * is fresh per test (RefreshDatabase) and the tests do not
     * depend on any platform-seeded row.
     *
     * @return void
     */
    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        // ---------------------------------------------------------------------
        // Tenants
        // ---------------------------------------------------------------------
        $this->tenantA = Tenant::create([
            'slug'      => 'gov-tenant-a',
            'name_am'   => 'ቴናንት ኤ',
            'name_en'   => 'Tenant A',
            'domain'    => 'a.gov.test',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'      => 'gov-tenant-b',
            'name_am'   => 'ቴናንት ቢ',
            'name_en'   => 'Tenant B',
            'domain'    => 'b.gov.test',
            'is_active' => true,
        ]);

        // ---------------------------------------------------------------------
        // Permissions — created here rather than seeded, so the test is
        // independent of GovernancePermissionsSeeder.
        // ---------------------------------------------------------------------
        $viewPermission   = $this->createGovernancePermission(
            'governance.body.view',
            'View governance bodies',
        );
        $managePermission = $this->createGovernancePermission(
            'governance.body.manage',
            'Manage governance bodies',
        );

        // ---------------------------------------------------------------------
        // Admin — a member of tenantA only, holding both permissions.
        // ---------------------------------------------------------------------
        $this->admin = User::create([
            'email'             => 'gov-admin-' . uniqid() . '@gov.test',
            'password'          => bcrypt('password'),
            'first_name_en'     => 'Gov',
            'father_name_en'    => 'Admin',
            'default_tenant_id' => $this->tenantA->id,
            'locale'            => 'am',
            'is_active'         => true,
        ]);

        $this->admin->tenants()->attach($this->tenantA->id, ['joined_at' => now()]);
        $this->admin->permissions()->attach([
            $viewPermission->id,
            $managePermission->id,
        ]);
        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Plain user — member of tenantA, no governance permissions.
        // ---------------------------------------------------------------------
        $this->plainUser = User::create([
            'email'             => 'gov-plain-' . uniqid() . '@gov.test',
            'password'          => bcrypt('password'),
            'first_name_en'     => 'Gov',
            'father_name_en'    => 'Plain',
            'default_tenant_id' => $this->tenantA->id,
            'locale'            => 'am',
            'is_active'         => true,
        ]);

        $this->plainUser->tenants()->attach($this->tenantA->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // Bodies — one in each tenant. The Amharic name on bodyB is
        // the leak marker used by the raw-body scans below.
        // ---------------------------------------------------------------------
        $this->bodyA = GovernanceBody::create([
            'tenant_id' => $this->tenantA->id,
            'code'      => 'A-BODY',
            'body_type' => 'board',
            'name_am'   => 'የቴናንት ኤ ቦርድ',
            'name_en'   => 'Tenant A Board',
            'is_active' => true,
        ]);

        $this->bodyB = GovernanceBody::create([
            'tenant_id' => $this->tenantB->id,
            'code'      => 'B-BODY',
            'body_type' => 'committee',
            'name_am'   => 'የተከለከለ አካል',  // leak marker
            'name_en'   => 'Tenant B Committee',
            'is_active' => true,
        ]);

        // ---------------------------------------------------------------------
        // Bind the tenant into the container so any FormRequest or
        // policy that resolves the tenant via `mases_tenant()` sees
        // the same tenant the fixtures were written against.
        // ---------------------------------------------------------------------
        $this->bindTenantContext($this->tenantA);

        gc_collect_cycles();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Authentication and tenant context
    // =========================================================================

    /**
     * A request without a Sanctum token is rejected with 401.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.bodies.index'));

        $response->assertStatus(401);
    }

    /**
     * A request without a tenant header is rejected by the
     * middleware stack.
     *
     * The exact status depends on the middleware's behaviour when
     * the tenant resolver returns null; the test accepts either
     * 400 or 422. Both are non-success and both carry the canonical
     * error envelope.
     */
    public function test_request_without_tenant_context_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(route('api.governance.bodies.index'));

        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    // =========================================================================
    // Authorization — RBAC
    // =========================================================================

    /**
     * A caller without the view permission receives 403 on the
     * index endpoint.
     */
    public function test_plain_user_cannot_list_bodies(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.bodies.index'));

        $response->assertStatus(403);
    }

    /**
     * A caller without the manage permission receives 403 on the
     * store endpoint.
     */
    public function test_plain_user_cannot_create_body(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'      => 'FORBIDDEN',
            'body_type' => 'board',
            'name_am'   => 'የተከለከለ ሙከራ',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Authorization must run before payload validation. An unauthorized
     * caller submitting malformed data must receive 403, not field-level
     * validation details that can expose database-backed checks.
     */
    public function test_unauthorized_create_is_rejected_before_validation(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'              => 'FORBIDDEN',
            'body_type'         => 'not-a-real-type',
            'parent_body_id'    => $this->bodyB->id,
            'reporting_body_id' => 999999999,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    // =========================================================================
    // Happy-path CRUD
    // =========================================================================

    /**
     * The index endpoint returns only the calling tenant's bodies.
     */
    public function test_index_returns_only_tenant_bodies(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.bodies.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $response->json('data'),
        );

        $this->assertContains(
            $this->bodyA->id,
            $ids,
            'tenantA body must appear in tenantA index.',
        );
        $this->assertNotContains(
            $this->bodyB->id,
            $ids,
            'tenantB body must NOT appear in tenantA index.',
        );

        // Semantic marker check — tenantB's Amharic name must not
        // appear in the raw response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'የተከለከለ አካል',
            $body,
            'tenantB body name must not leak into tenantA index.',
        );
    }

    /**
     * A caller with the view permission can read a specific body in
     * the same tenant.
     */
    public function test_authorized_user_can_show_body(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.bodies.show', [
            'governanceBody' => $this->bodyA->id,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.code', 'A-BODY')
            ->assertJsonPath('data.body_type', 'board');
    }

    /**
     * A caller with the manage permission can create a body; the
     * response carries the created resource with status 201.
     */
    public function test_authorized_user_can_create_body(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'      => 'NEW-BODY',
            'body_type' => 'committee',
            'name_am'   => 'አዲስ ኮሚቴ',
            'name_en'   => 'New Committee',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'NEW-BODY')
            ->assertJsonPath('data.body_type', 'committee')
            ->assertJsonPath('data.is_standing', true)
            ->assertJsonPath('data.is_temporary', false);

        $this->assertDatabaseHas('governance_bodies', [
            'code'      => 'NEW-BODY',
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    /**
     * A caller with the manage permission can update a body.
     */
    public function test_authorized_user_can_update_body(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.bodies.update', [
            'governanceBody' => $this->bodyA->id,
        ]), [
            'name_en' => 'Renamed Board',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name_en', 'Renamed Board');

        $this->assertDatabaseHas('governance_bodies', [
            'id'      => $this->bodyA->id,
            'name_en' => 'Renamed Board',
        ]);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    /**
     * A body belonging to tenantB is not reachable from tenantA.
     *
     * The response is 404, not 403 — a caller cannot use the API
     * to discover whether a given body id exists in another tenant.
     */
    public function test_body_from_other_tenant_is_not_reachable(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.bodies.show', [
            'governanceBody' => $this->bodyB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    /**
     * A forged X-Tenant-ID header is rejected by
     * `TenantMembershipMiddleware`.
     *
     * The admin is a member of tenantA only. Sending tenantB's id in
     * the header attempts to elevate context. The middleware
     * resolves the header, checks membership, and rejects with 403
     * `TENANT_ACCESS_DENIED` before any controller or permission
     * check runs.
     */
    public function test_tenant_header_cannot_be_forged(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ])->getJson(route('api.governance.bodies.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // A rejected cross-tenant request must not return a payload.
        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );

        // Semantic leak check — tenantB's Amharic name must not appear.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'የተከለከለ አካል',
            $body,
            'tenantB body name must not leak into a rejected request response.',
        );
    }

    // =========================================================================
    // Validation — shape
    // =========================================================================

    /**
     * A body with a duplicate code in the same tenant is rejected
     * with a 422 naming the `code` field.
     */
    public function test_duplicate_code_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'      => 'A-BODY',  // collides with bodyA
            'body_type' => 'committee',
            'name_am'   => 'የሁለተኛ',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * A body with an unrecognized `body_type` is rejected with a
     * 422 naming the `body_type` field.
     */
    public function test_unknown_body_type_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'      => 'BAD-TYPE',
            'body_type' => 'nonsense',
            'name_am'   => 'የተሳሳተ ዓይነት',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['body_type']);
    }

    /**
     * A body with neither `name_am` nor `name_en` is rejected with a
     * 422 naming the `name_am` field.
     */
    public function test_body_without_any_name_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'      => 'NO-NAME',
            'body_type' => 'committee',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name_am']);
    }

    // =========================================================================
    // Validation — domain invariants (service layer)
    // =========================================================================

    /**
     * An attempt to change a body's `body_type` after registration is
     * rejected with a 422 naming the `body_type` field.
     */
    public function test_body_type_is_immutable_after_registration(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.bodies.update', [
            'governanceBody' => $this->bodyA->id,
        ]), [
            'body_type' => 'committee',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['body_type']);

        // The original body_type must be unchanged.
        $this->bodyA->refresh();
        $this->assertSame('board', $this->bodyA->body_type);
    }

    /**
     * A body whose `effective_from` is after its `effective_until` is
     * rejected with a 422 naming the `effective_until` field.
     */
    public function test_invalid_effective_date_range_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'            => 'BAD-DATES',
            'body_type'       => 'committee',
            'name_am'         => 'የተሳሳተ ቀን',
            'effective_from'  => '2026-12-31',
            'effective_until' => '2026-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['effective_until']);
    }

    // =========================================================================
    // Audit
    // =========================================================================

    /**
     * A successful create writes exactly one audit row with the
     * action slug `governance.body.registered`.
     */
    public function test_create_writes_audit_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.bodies.store'), [
            'code'      => 'AUDIT-CREATE',
            'body_type' => 'board',
            'name_am'   => 'የኦዲት ሙከራ',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'governance.body.registered',
            'resource_type' => 'GovernanceBody',
            'tenant_id'     => $this->tenantA->id,
        ]);

        $this->assertEquals(
            1,
            DB::table('audit_logs')
                ->where('action', 'governance.body.registered')
                ->count(),
        );
    }

    /**
     * A successful update writes exactly one audit row with the
     * action slug `governance.body.updated`.
     */
    public function test_update_writes_audit_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.bodies.update', [
            'governanceBody' => $this->bodyA->id,
        ]), [
            'name_en' => 'Updated Board',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'governance.body.updated',
            'resource_type' => 'GovernanceBody',
            'resource_id'   => (string) $this->bodyA->id,
            'tenant_id'     => $this->tenantA->id,
        ]);

        $this->assertEquals(
            1,
            DB::table('audit_logs')
                ->where('action', 'governance.body.updated')
                ->count(),
        );
    }

    // =========================================================================
    // Audit — RP-06 / A3 before-and-after integrity guards
    // =========================================================================

    /**
     * RP-06 / A3 — a successful update records the exact pre-change
     * value in `old_values` and the exact post-change value in
     * `new_values` for the attribute that changed, and does not
     * include unchanged attributes in either map.
     *
     * Establishes F1 cardinality with precise filters before reading
     * the row (never selects the "latest" row as proof of uniqueness).
     * Reads `old_values` / `new_values` through the `AuditLog` model's
     * declared `array` casts (F4). Asserts the exact expected values
     * (F3) rather than "values differ".
     */
    public function test_update_audit_row_records_exact_before_and_after_values(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Pre-condition — the persisted row carries the fixture's
        // initial values. This is what the listener must record as
        // "before".
        $this->assertSame('A-BODY',           $this->bodyA->code);
        $this->assertSame('board',            $this->bodyA->body_type);
        $this->assertSame('የቴናንት ኤ ቦርድ',    $this->bodyA->name_am);
        $this->assertSame('Tenant A Board',   $this->bodyA->name_en);
        $this->assertTrue((bool) $this->bodyA->is_active);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.bodies.update', [
            'governanceBody' => $this->bodyA->id,
        ]), [
            'name_en' => 'Renamed For Audit',
        ])->assertStatus(200);

        // F1 — exact expected count with precise filters that
        // distinguish the target entity, the action, and the tenant.
        $query = AuditLog::query()
            ->where('action',        'governance.body.updated')
            ->where('resource_type', 'GovernanceBody')
            ->where('resource_id',   (string) $this->bodyA->id)
            ->where('tenant_id',     $this->tenantA->id);

        $this->assertSame(
            1,
            $query->count(),
            'Exactly one governance.body.updated audit row must exist for this body.',
        );

        /** @var AuditLog $row */
        $row = $query->firstOrFail();

        // F4 — the AuditLog model casts old_values / new_values to
        // PHP arrays; no manual json_decode of raw column strings.
        $old = $row->old_values;
        $new = $row->new_values;

        $this->assertIsArray($old);
        $this->assertIsArray($new);

        // F3 — exact expected before/after values on the changed key.
        $this->assertArrayHasKey('name_en', $old);
        $this->assertArrayHasKey('name_en', $new);
        $this->assertSame('Tenant A Board',    $old['name_en']);
        $this->assertSame('Renamed For Audit', $new['name_en']);

        // Unchanged attributes must not be misrepresented as changed.
        foreach (['code', 'name_am', 'body_type', 'is_active'] as $unchanged) {
            $this->assertArrayNotHasKey(
                $unchanged,
                $old,
                sprintf('Unchanged attribute "%s" must not appear in old_values.', $unchanged),
            );
            $this->assertArrayNotHasKey(
                $unchanged,
                $new,
                sprintf('Unchanged attribute "%s" must not appear in new_values.', $unchanged),
            );
        }
    }

    /**
     * RP-06 / A3 — a status update that flips `is_active` from true
     * to false records `is_active` in both `old_values` and
     * `new_values` with the exact pre/post values.
     *
     * F2 — the GovernanceBody HTTP surface (per the routes declared
     * for G2a) exposes only index / show / store / update. There is
     * no dedicated deactivate route. A PATCH that sets
     * `is_active = false` through the generic update endpoint is a
     * STATUS UPDATE and is described as such here — not as a
     * deactivation operation.
     *
     * F3 — asserts the exact transition `true → false`, not merely
     * that the two values differ.
     */
    public function test_status_update_via_update_endpoint_records_exact_before_and_after_values(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->assertTrue((bool) $this->bodyA->is_active);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.bodies.update', [
            'governanceBody' => $this->bodyA->id,
        ]), [
            'is_active' => false,
        ])->assertStatus(200);

        // F1 — exact expected count with precise filters.
        $query = AuditLog::query()
            ->where('action',        'governance.body.updated')
            ->where('resource_type', 'GovernanceBody')
            ->where('resource_id',   (string) $this->bodyA->id)
            ->where('tenant_id',     $this->tenantA->id);

        $this->assertSame(
            1,
            $query->count(),
            'Exactly one governance.body.updated audit row must exist for this body.',
        );

        /** @var AuditLog $row */
        $row = $query->firstOrFail();

        $old = $row->old_values;
        $new = $row->new_values;

        $this->assertIsArray($old);
        $this->assertIsArray($new);

        // F3 — exact transition true → false.
        $this->assertArrayHasKey('is_active', $old);
        $this->assertArrayHasKey('is_active', $new);
        $this->assertTrue(
            (bool) $old['is_active'],
            'old_values.is_active must be true.',
        );
        $this->assertFalse(
            (bool) $new['is_active'],
            'new_values.is_active must be false.',
        );

        // Unchanged business attributes must not appear.
        foreach (['code', 'name_am', 'name_en', 'body_type'] as $unchanged) {
            $this->assertArrayNotHasKey($unchanged, $old);
            $this->assertArrayNotHasKey($unchanged, $new);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Bind a tenant into the service container under the conventional
     * names the domain uses for tenant resolution.
     *
     * Safe to call multiple times — `app()->instance()` overwrites.
     *
     * @param  Tenant $tenant
     * @return void
     */
    private function bindTenantContext(Tenant $tenant): void
    {
        $this->app->instance('tenant', $tenant);
        $this->app->instance('mases.tenant', $tenant);
        $this->app->instance('current_tenant', $tenant);
    }

    /**
     * Create a governance-domain permission.
     *
     * Named `createGovernancePermission()` — not `createPermission()` —
     * to avoid a method-name collision with the base `Tests\TestCase`.
     * The same naming discipline the Shareholder domain's
     * `TenantIsolationTest` uses.
     *
     * The permission is not attached to any user by this helper; the
     * caller attaches it. This keeps the helper single-purpose.
     *
     * @param  string $slug
     * @param  string $label
     * @return Permission
     */
    private function createGovernancePermission(string $slug, string $label): Permission
    {
        return Permission::create([
            'slug'           => $slug,
            'name_am'        => $label,
            'name_en'        => $label,
            'description_am' => $label,
            'description_en' => $label,
            'category'       => 'governance',
            'scope'          => 'tenant',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
            'is_system'      => true,
        ]);
    }

    public function test_update_audit_row_carries_listener_metadata(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.bodies.update', [
            'governanceBody' => $this->bodyA->id,
        ]), [
            'name_en' => 'Metadata Probe',
        ])->assertStatus(200);

        $row = DB::table('audit_logs')
            ->where('action',        'governance.body.updated')
            ->where('resource_type', 'GovernanceBody')
            ->where('resource_id',   (string) $this->bodyA->id)
            ->where('tenant_id',     $this->tenantA->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->metadata);

        $meta = json_decode($row->metadata, true);
        $this->assertIsArray($meta);
        $this->assertSame('event-listener', $meta['source'] ?? null);
        $this->assertSame(
            'MaaleArna\\MASES\\Core\\Domains\\Governance\\Events\\GovernanceBodyUpdated',
            $meta['event'] ?? null,
        );
    }}