<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 12 of 27
 * =============================================================================
 *
 * File:            Tests/Feature/GovernancePositionTest.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (feature test)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The Governance domain had feature tests
 *   for `GovernanceBody` (G2a file 17 of 18) but none for
 *   `GovernancePosition`. A regression in the position policy's
 *   tenant check, the service's invariant validation, the listener's
 *   audit write, or any of the four HTTP endpoints would go
 *   undetected until a human ran the smoke tests.
 *
 * After:
 *   One feature test class exercising the G2b position vertical
 *   slice through the HTTP layer, end to end:
 *
 *     · Authentication   — no Sanctum token → 401.
 *     · Tenant context   — no X-Tenant-ID → non-success.
 *     · RBAC             — a plain user cannot list or create.
 *     · Happy path       — index, show, store, update succeed.
 *     · Tenant isolation — cross-tenant body or position → 404.
 *     · Validation       — duplicate code within a body → 422;
 *                          cross-body code reuse is allowed;
 *                          missing name → 422;
 *                          `governance_body_id` immutability
 *                          on update → 422.
 *     · Audit            — create and update each write exactly
 *                          one row to `audit_logs`.
 *
 *   The class is deliberately narrow. It tests what G2b promises
 *   for the position slice and nothing beyond. Terms have their own
 *   test class (G2b file 24 of 27).
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
 * status update — the G2b position HTTP surface exposes no dedicated
 * deactivate route — and (F3) the exact expected before/after values,
 * read through the AuditLog model's `old_values` / `new_values` array
 * casts (F4).
 *
 * Nothing else in the file is changed.
 *
 * =============================================================================
 * Runnability — read before running
 * =============================================================================
 * This test class cannot pass until G2b files 25, 26, and 27 are
 * deployed:
 *
 *     file 25 — audit listener amendment (adds position-event
 *               handling; without it, no audit rows are written for
 *               positions, and the two audit tests fail).
 *     file 26 — provider amendment (adds two container bindings,
 *               one Gate::policy registration, and two Event::listen
 *               registrations; without it, the Gate has no policy
 *               for GovernancePosition and the routes 403 by
 *               default).
 *     file 27 — routes amendment (adds four position routes; without
 *               it, every URL returns 404).
 *
 * The whole G2b sub-phase is one logical unit; the test file is
 * delivered as part of the position slice because the position
 * classes are what it tests, but the entire unit must be deployed
 * before the test can run.
 *
 * The same applies to the terms test (G2b file 24 of 27): it cannot
 * run until files 25–27 are deployed.
 *
 * =============================================================================
 * Fixture topology
 * =============================================================================
 *   tenantA         — the caller's tenant. The admin is a member.
 *   tenantB         — the foreign tenant. The admin is NOT a member.
 *   admin           — holds governance.body.view and
 *                     governance.body.manage in tenantA.
 *   plainUser       — holds no governance permissions.
 *   bodyA1          — a body in tenantA (the primary body under test).
 *   bodyB1          — a body in tenantB (for cross-tenant tests).
 *   bodyA2          — a second body in tenantA (for cross-body code
 *                     reuse tests).
 *   positionA1      — a position on bodyA1.
 *   positionB1      — a position on bodyB1.
 *
 * =============================================================================
 * Raw-body scan policy
 * =============================================================================
 * Where a test scans the raw response body for evidence of
 * cross-tenant leakage, the scan target is the position's Amharic
 * name or code — a semantically meaningful, unambiguously scoped
 * marker. Numeric ids are not used as scan targets: a single-digit
 * id collides with timestamps, UUIDs, and exception line numbers,
 * producing false positives. This mirrors the Shareholder
 * `TenantIsolationTest` v1.2.0.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of every test, following
 * the Shareholder suite and the G2a GovernanceBody test. This keeps
 * the Plesk CLI PHP memory limit from being exhausted by
 * accumulated fixture references.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createGovernancePermission()` — not `createPermission()` — to
 * avoid a method-name collision with the base `Tests\TestCase`.
 * Same helper name used by the G2a GovernanceBody test.
 *
 * =============================================================================
 * Running the tests
 * =============================================================================
 * Development environment with SSH:
 *
 *     php artisan test --filter=GovernancePositionTest
 *
 * Plesk shared hosting without SSH — two options:
 *
 *   1. Plesk's PHP Command runner (Tools & Settings → PHP →
 *      Commands), if your plan includes it. Run:
 *          php artisan test --filter=GovernancePositionTest
 *
 *   2. Plesk Scheduled Tasks — a one-off task with the same
 *      command, run once, disabled after.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Test methods:              15
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * GovernancePositionTest
 *
 * Feature tests for the GovernancePosition vertical slice — the four
 * endpoints delivered by G2b.
 *
 * See the file-level change-verification block for the test's scope,
 * its runnability requirements (files 25–27 of G2b must be deployed
 * before the test can pass), its fixture topology, and its note on
 * running without SSH.
 *
 * =============================================================================
 * Endpoints exercised
 * =============================================================================
 *   GET    /api/governance/bodies/{governanceBody}/positions
 *              → api.governance.positions.index
 *   POST   /api/governance/bodies/{governanceBody}/positions
 *              → api.governance.positions.store
 *   GET    /api/governance/positions/{governancePosition}
 *              → api.governance.positions.show
 *   PATCH  /api/governance/positions/{governancePosition}
 *              → api.governance.positions.update
 *
 * The route names are used in the tests via `route(...)`. The
 * parameter names (`governanceBody`, `governancePosition`) match
 * the routes file's declarations exactly.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Tests\Feature
 * @version 1.0.0
 */
final class GovernancePositionTest extends TestCase
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
     * A user holding the two body permissions in tenantA.
     *
     * The permissions are the ones the position policy reuses:
     * `governance.body.view` and `governance.body.manage`.
     */
    private User $admin;

    /**
     * A user belonging to tenantA but holding no governance
     * permissions.
     */
    private User $plainUser;

    /**
     * A governance body in tenantA. The primary body under test.
     */
    private GovernanceBody $bodyA1;

    /**
     * A second governance body in tenantA.
     *
     * Used for the "same code on different bodies" test.
     */
    private GovernanceBody $bodyA2;

    /**
     * A governance body in tenantB.
     *
     * Its Amharic name is used as the semantic leak marker in
     * raw-body scans.
     */
    private GovernanceBody $bodyB1;

    /**
     * A position on `bodyA1`.
     */
    private GovernancePosition $positionA1;

    /**
     * A position on `bodyB1`. Its Amharic name
     * (`የተከለከለ ቦታ`) is used as the semantic leak marker in
     * raw-body scans.
     */
    private GovernancePosition $positionB1;

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
     * IMPORTANT: this test can only pass when G2b files 25–27 are
     * deployed. See the file-level change-verification block
     * §"Runnability — read before running".
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
            'slug'      => 'pos-tenant-a',
            'name_am'   => 'ቴናንት ኤ',
            'name_en'   => 'Tenant A',
            'domain'    => 'a.pos.test',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'      => 'pos-tenant-b',
            'name_am'   => 'ቴናንት ቢ',
            'name_en'   => 'Tenant B',
            'domain'    => 'b.pos.test',
            'is_active' => true,
        ]);

        // ---------------------------------------------------------------------
        // Permissions — created here, attached directly to the admin.
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
            'email'             => 'pos-admin-' . uniqid() . '@pos.test',
            'password'          => bcrypt('password'),
            'first_name_en'     => 'Pos',
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
            'email'             => 'pos-plain-' . uniqid() . '@pos.test',
            'password'          => bcrypt('password'),
            'first_name_en'     => 'Pos',
            'father_name_en'    => 'Plain',
            'default_tenant_id' => $this->tenantA->id,
            'locale'            => 'am',
            'is_active'         => true,
        ]);

        $this->plainUser->tenants()->attach($this->tenantA->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // Bodies — two in tenantA, one in tenantB.
        // ---------------------------------------------------------------------
        $this->bodyA1 = GovernanceBody::create([
            'tenant_id' => $this->tenantA->id,
            'code'      => 'A1-BOARD',
            'body_type' => 'board',
            'name_am'   => 'የቴናንት ኤ ቦርድ',
            'name_en'   => 'Tenant A Board',
            'is_active' => true,
        ]);

        $this->bodyA2 = GovernanceBody::create([
            'tenant_id' => $this->tenantA->id,
            'code'      => 'A2-COMMITTEE',
            'body_type' => 'committee',
            'name_am'   => 'የቴናንት ኤ ኮሚቴ',
            'name_en'   => 'Tenant A Committee',
            'is_active' => true,
        ]);

        $this->bodyB1 = GovernanceBody::create([
            'tenant_id' => $this->tenantB->id,
            'code'      => 'B1-BOARD',
            'body_type' => 'board',
            'name_am'   => 'የቴናንት ቢ ቦርድ',
            'name_en'   => 'Tenant B Board',
            'is_active' => true,
        ]);

        // ---------------------------------------------------------------------
        // Positions — one on bodyA1, one on bodyB1. The Amharic name on
        // positionB1 is the leak marker used by the raw-body scans.
        // ---------------------------------------------------------------------
        $this->positionA1 = GovernancePosition::create([
            'tenant_id'          => $this->tenantA->id,
            'governance_body_id' => $this->bodyA1->id,
            'code'               => 'CHAIRPERSON',
            'name_am'            => 'ሰብሳቢ',
            'name_en'            => 'Chairperson',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 10,
            'is_active'          => true,
        ]);

        $this->positionB1 = GovernancePosition::create([
            'tenant_id'          => $this->tenantB->id,
            'governance_body_id' => $this->bodyB1->id,
            'code'               => 'CHAIRPERSON',
            'name_am'            => 'የተከለከለ ቦታ',  // leak marker
            'name_en'            => 'Forbidden Position',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 10,
            'is_active'          => true,
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
        ])->getJson(route('api.governance.positions.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $response->assertStatus(401);
    }

    /**
     * A request without a tenant header is rejected by the
     * middleware stack.
     */
    public function test_request_without_tenant_context_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(route('api.governance.positions.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    // =========================================================================
    // Authorization — RBAC
    // =========================================================================

    /**
     * A caller without the view permission receives 403 on the
     * index endpoint.
     */
    public function test_plain_user_cannot_list_positions(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.positions.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $response->assertStatus(403);
    }

    /**
     * A caller without the manage permission receives 403 on the
     * store endpoint.
     */
    public function test_plain_user_cannot_create_position(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'code'    => 'FORBIDDEN',
            'name_am' => 'የተከለከለ ሙከራ',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // Happy-path CRUD
    // =========================================================================

    /**
     * The index endpoint returns only the calling body's positions.
     */
    public function test_index_returns_only_body_positions(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.positions.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $response->json('data'),
        );

        $this->assertContains(
            $this->positionA1->id,
            $ids,
            'positionA1 must appear in the bodyA1 index.',
        );
        $this->assertNotContains(
            $this->positionB1->id,
            $ids,
            'positionB1 must NOT appear in the bodyA1 index.',
        );

        // Semantic marker check — positionB1's Amharic name must not
        // appear in the raw response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'የተከለከለ ቦታ',
            $body,
            'positionB1 name must not leak into the bodyA1 index.',
        );
    }

    /**
     * A caller with the view permission can read a specific position.
     */
    public function test_authorized_user_can_show_position(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.positions.show', [
            'governancePosition' => $this->positionA1->id,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.code', 'CHAIRPERSON')
            ->assertJsonPath('data.is_voting', true)
            ->assertJsonPath('data.is_officer', true);
    }

    /**
     * A caller with the manage permission can create a position; the
     * response carries the created resource with status 201.
     */
    public function test_authorized_user_can_create_position(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'code'       => 'SECRETARY',
            'name_am'    => 'ጸሐፊ',
            'name_en'    => 'Secretary',
            'is_voting'  => false,
            'is_officer' => true,
            'sort_order' => 20,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'SECRETARY')
            ->assertJsonPath('data.name_am', 'ጸሐፊ')
            ->assertJsonPath('data.governance_body_id', $this->bodyA1->id);

        $this->assertDatabaseHas('governance_positions', [
            'code'               => 'SECRETARY',
            'tenant_id'          => $this->tenantA->id,
            'governance_body_id' => $this->bodyA1->id,
        ]);
    }

    /**
     * A caller with the manage permission can update a position.
     */
    public function test_authorized_user_can_update_position(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.positions.update', [
            'governancePosition' => $this->positionA1->id,
        ]), [
            'name_en' => 'Chair',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name_en', 'Chair');

        $this->assertDatabaseHas('governance_positions', [
            'id'      => $this->positionA1->id,
            'name_en' => 'Chair',
        ]);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    /**
     * A position belonging to tenantB is not reachable from tenantA.
     */
    public function test_position_from_other_tenant_is_not_reachable(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.positions.show', [
            'governancePosition' => $this->positionB1->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    /**
     * A body belonging to tenantB is not reachable from tenantA for
     * the index action.
     */
    public function test_index_for_other_tenant_body_is_not_reachable(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.positions.index', [
            'governanceBody' => $this->bodyB1->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    /**
     * A forged X-Tenant-ID header is rejected by
     * `TenantMembershipMiddleware`.
     */
    public function test_tenant_header_cannot_be_forged(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ])->getJson(route('api.governance.positions.index', [
            'governanceBody' => $this->bodyB1->id,
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'የተከለከለ ቦታ',
            $body,
            'tenantB position name must not leak into a rejected request response.',
        );
    }

    // =========================================================================
    // Validation — shape and invariants
    // =========================================================================

    /**
     * A position with a duplicate code within the same body is
     * rejected with a 422 naming the `code` field.
     */
    public function test_duplicate_code_within_body_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'code'    => 'CHAIRPERSON',  // collides with positionA1
            'name_am' => 'ሌላ ሰብሳቢ',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * The same code CAN be used on a different body within the same
     * tenant.
     *
     * Positions are unique per body, not per tenant. A body's
     * "CHAIRPERSON" and another body's "CHAIRPERSON" are distinct
     * positions.
     */
    public function test_same_code_is_allowed_on_different_body(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA2->id,  // a different body
        ]), [
            'code'    => 'CHAIRPERSON',  // same code as positionA1
            'name_am' => 'የኮሚቴ ሰብሳቢ',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'CHAIRPERSON');
        $response->assertJsonPath('data.governance_body_id', $this->bodyA2->id);
    }

    /**
     * A position with neither `name_am` nor `name_en` is rejected
     * with a 422 naming the `name_am` field.
     */
    public function test_position_without_any_name_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'code' => 'NO-NAME',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name_am']);
    }

    /**
     * An attempt to change a position's `governance_body_id` after
     * registration is rejected with a 422 naming the
     * `governance_body_id` field.
     */
    public function test_body_id_is_immutable_after_registration(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.positions.update', [
            'governancePosition' => $this->positionA1->id,
        ]), [
            'governance_body_id' => $this->bodyA2->id,  // different body
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['governance_body_id']);

        // The original body id must be unchanged.
        $this->positionA1->refresh();
        $this->assertSame($this->bodyA1->id, $this->positionA1->governance_body_id);
    }

    // =========================================================================
    // Audit — requires G2b file 25 of 27
    // =========================================================================

    /**
     * A successful create writes exactly one audit row with the
     * action slug `governance.position.registered`.
     *
     * Requires G2b file 25 of 27 (the audit listener amendment) to
     * be deployed. See the file-level change-verification block
     * §"Runnability — read before running".
     */
    public function test_create_writes_audit_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'code'    => 'AUDIT-POSITION',
            'name_am' => 'የኦዲት ቦታ',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'governance.position.registered',
            'resource_type' => 'GovernancePosition',
            'tenant_id'     => $this->tenantA->id,
        ]);

        $this->assertEquals(
            1,
            DB::table('audit_logs')
                ->where('action', 'governance.position.registered')
                ->count(),
        );
    }

    /**
     * A successful update writes exactly one audit row with the
     * action slug `governance.position.updated`.
     *
     * Requires G2b file 25 of 27 (the audit listener amendment) to
     * be deployed.
     */
    public function test_update_writes_audit_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.positions.update', [
            'governancePosition' => $this->positionA1->id,
        ]), [
            'name_en' => 'Audited Chairperson',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'governance.position.updated',
            'resource_type' => 'GovernancePosition',
            'resource_id'   => (string) $this->positionA1->id,
            'tenant_id'     => $this->tenantA->id,
        ]);

        $this->assertEquals(
            1,
            DB::table('audit_logs')
                ->where('action', 'governance.position.updated')
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
     * the row. Reads `old_values` / `new_values` through the
     * `AuditLog` model's declared `array` casts (F4). Asserts exact
     * expected values (F3), not "values differ".
     */
    public function test_update_audit_row_records_exact_before_and_after_values(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Pre-condition — the persisted row carries the fixture's
        // initial values. This is what the listener must record as
        // "before".
        $this->assertSame('CHAIRPERSON',       $this->positionA1->code);
        $this->assertSame('ሰብሳቢ',             $this->positionA1->name_am);
        $this->assertSame('Chairperson',       $this->positionA1->name_en);
        $this->assertSame($this->bodyA1->id,   $this->positionA1->governance_body_id);
        $this->assertTrue((bool) $this->positionA1->is_active);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.positions.update', [
            'governancePosition' => $this->positionA1->id,
        ]), [
            'name_en' => 'Renamed For Audit',
        ])->assertStatus(200);

        // F1 — exact expected count with precise filters.
        $query = AuditLog::query()
            ->where('action',        'governance.position.updated')
            ->where('resource_type', 'GovernancePosition')
            ->where('resource_id',   (string) $this->positionA1->id)
            ->where('tenant_id',     $this->tenantA->id);

        $this->assertSame(
            1,
            $query->count(),
            'Exactly one governance.position.updated audit row must exist for this position.',
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
        $this->assertSame('Chairperson',       $old['name_en']);
        $this->assertSame('Renamed For Audit', $new['name_en']);

        // Unchanged attributes must not be misrepresented as changed.
        foreach (['code', 'name_am', 'governance_body_id', 'is_active'] as $unchanged) {
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
     * F2 — the GovernancePosition HTTP surface (per the routes
     * declared for G2b) exposes only index / show / store / update.
     * There is no dedicated deactivate route. A PATCH that sets
     * `is_active = false` through the generic update endpoint is a
     * STATUS UPDATE and is described as such here.
     *
     * F3 — asserts the exact transition `true → false`, not merely
     * that the two values differ.
     */
    public function test_status_update_via_update_endpoint_records_exact_before_and_after_values(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->assertTrue((bool) $this->positionA1->is_active);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.positions.update', [
            'governancePosition' => $this->positionA1->id,
        ]), [
            'is_active' => false,
        ])->assertStatus(200);

        // F1 — exact expected count with precise filters.
        $query = AuditLog::query()
            ->where('action',        'governance.position.updated')
            ->where('resource_type', 'GovernancePosition')
            ->where('resource_id',   (string) $this->positionA1->id)
            ->where('tenant_id',     $this->tenantA->id);

        $this->assertSame(
            1,
            $query->count(),
            'Exactly one governance.position.updated audit row must exist for this position.',
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
        foreach (['code', 'name_am', 'name_en', 'governance_body_id'] as $unchanged) {
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
     * @param  Tenant $tenant
     * @return void
     */
    private function bindTenantContext(Tenant $tenant): void
    {
        $this->app->instance('tenant', $tenant);
        $this->app->instance('mases.tenant', $tenant);
        $this->app->instance('current_tenant', $tenant);
    }

    /** Unauthorized malformed create must be rejected before validation. */
    public function test_unauthorized_create_is_rejected_before_validation(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.positions.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), ['code' => ['invalid-type'], 'name_am' => null, 'name_en' => null]);

        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    /** Unauthorized malformed update must be rejected before validation. */
    public function test_unauthorized_update_is_rejected_before_validation(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.positions.update', [
            'governancePosition' => $this->positionA1->id,
        ]), ['code' => ['invalid-type'], 'name_am' => null, 'name_en' => null]);

        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    /**
     * Create a governance-domain permission.
     *
     * Named `createGovernancePermission()` — not `createPermission()` —
     * to avoid a method-name collision with the base `Tests\TestCase`.
     * Same naming discipline as the G2a GovernanceBody test.
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
}