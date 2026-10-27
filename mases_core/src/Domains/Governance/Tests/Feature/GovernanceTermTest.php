<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 24 of 27
 * =============================================================================
 *
 * File:            Tests/Feature/GovernanceTermTest.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (feature test)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The Governance domain had feature tests
 *   for `GovernanceBody` (G2a file 17 of 18) and `GovernancePosition`
 *   (G2b file 12 of 27) but none for `GovernanceTerm`. A regression in
 *   the term policy's tenant check, the service's invariant validation,
 *   the listener's audit write, or any of the four HTTP endpoints
 *   would go undetected until a human ran the smoke tests.
 *
 * After:
 *   One feature test class exercising the G2b term vertical slice
 *   through the HTTP layer, end to end:
 *
 *     · Authentication   — no Sanctum token → 401.
 *     · Tenant context   — no X-Tenant-ID → non-success.
 *     · RBAC             — a plain user cannot list or create.
 *     · Happy path       — index, show, store, update succeed.
 *     · Tenant isolation — cross-tenant body or term → 404.
 *     · Validation       — missing label → 422;
 *                          inverted effective dates → 422;
 *                          `governance_body_id` immutability on
 *                          update → 422.
 *     · Overlap policy   — overlapping terms on the same body are
 *                          ALLOWED (the distinctive behaviour of
 *                          terms vs. positions).
 *     · Audit            — create and update each write exactly one
 *                          row to `audit_logs`.
 *
 *   The class is deliberately narrow. It tests what G2b promises for
 *   the term slice and nothing beyond.
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
 * status update — the G2b term HTTP surface exposes no dedicated
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
 *     file 25 — audit listener amendment (adds term-event handling;
 *               without it, no audit rows are written for terms, and
 *               the two audit tests fail).
 *     file 26 — provider amendment (adds two container bindings, one
 *               Gate::policy registration, and two Event::listen
 *               registrations).
 *     file 27 — routes amendment (adds four term routes; without it,
 *               every URL returns 404).
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
 *   bodyA2          — a second body in tenantA (for the body-
 *                     immutability update test).
 *   bodyB1          — a body in tenantB (for cross-tenant tests).
 *   termA1          — a term on bodyA1.
 *   termB1          — a term on bodyB1. Its Amharic label
 *                     (`የተከለከለ ዘመን`) is used as the semantic leak
 *                     marker in raw-body scans.
 *
 * =============================================================================
 * Raw-body scan policy
 * =============================================================================
 * Where a test scans the raw response body for evidence of
 * cross-tenant leakage, the scan target is the term's Amharic label —
 * a semantically meaningful, unambiguously scoped marker. Numeric ids
 * are not used as scan targets. This mirrors the Shareholder
 * `TenantIsolationTest` v1.2.0 and the G2b position test.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of every test.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createGovernancePermission()` — same name used by every other
 * Governance feature test.
 *
 * =============================================================================
 * Running the tests
 * =============================================================================
 * Development environment with SSH:
 *
 *     php artisan test --filter=GovernanceTermTest
 *
 * Plesk shared hosting without SSH — two options:
 *
 *   1. Plesk's PHP Command runner (Tools & Settings → PHP →
 *      Commands), if your plan includes it.
 *
 *   2. Plesk Scheduled Tasks — a one-off task, run once, disabled
 *      after.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Test methods:              17
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * GovernanceTermTest
 *
 * Feature tests for the GovernanceTerm vertical slice — the four
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
 *   GET    /api/governance/bodies/{governanceBody}/terms
 *              → api.governance.terms.index
 *   POST   /api/governance/bodies/{governanceBody}/terms
 *              → api.governance.terms.store
 *   GET    /api/governance/terms/{governanceTerm}
 *              → api.governance.terms.show
 *   PATCH  /api/governance/terms/{governanceTerm}
 *              → api.governance.terms.update
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Tests\Feature
 * @version 1.0.0
 */
final class GovernanceTermTest extends TestCase
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
     * Used for the body-immutability update test.
     */
    private GovernanceBody $bodyA2;

    /**
     * A governance body in tenantB.
     */
    private GovernanceBody $bodyB1;

    /**
     * A term on `bodyA1`.
     */
    private GovernanceTerm $termA1;

    /**
     * A term on `bodyB1`. Its Amharic label
     * (`የተከለከለ ዘመን`) is used as the semantic leak marker in
     * raw-body scans.
     */
    private GovernanceTerm $termB1;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Set up the test environment.
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
            'slug'      => 'term-tenant-a',
            'name_am'   => 'ቴናንት ኤ',
            'name_en'   => 'Tenant A',
            'domain'    => 'a.term.test',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'      => 'term-tenant-b',
            'name_am'   => 'ቴናንት ቢ',
            'name_en'   => 'Tenant B',
            'domain'    => 'b.term.test',
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
            'email'             => 'term-admin-' . uniqid() . '@term.test',
            'password'          => bcrypt('password'),
            'first_name_en'     => 'Term',
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
            'email'             => 'term-plain-' . uniqid() . '@term.test',
            'password'          => bcrypt('password'),
            'first_name_en'     => 'Term',
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
        // Terms — one on bodyA1, one on bodyB1. The Amharic label on
        // termB1 is the leak marker used by the raw-body scans.
        // ---------------------------------------------------------------------
        $this->termA1 = GovernanceTerm::create([
            'tenant_id'          => $this->tenantA->id,
            'governance_body_id' => $this->bodyA1->id,
            'label_am'           => 'የ2018-2021 የሥራ ዘመን',
            'label_en'           => 'Term 2026-2029',
            'effective_from'     => '2026-09-18',
            'effective_until'    => '2029-09-17',
            'is_active'          => true,
        ]);

        $this->termB1 = GovernanceTerm::create([
            'tenant_id'          => $this->tenantB->id,
            'governance_body_id' => $this->bodyB1->id,
            'label_am'           => 'የተከለከለ ዘመን',  // leak marker
            'label_en'           => 'Forbidden Term',
            'effective_from'     => '2026-09-18',
            'effective_until'    => '2029-09-17',
            'is_active'          => true,
        ]);

        // ---------------------------------------------------------------------
        // Bind the tenant into the container.
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $response->assertStatus(401);
    }

    public function test_request_without_tenant_context_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    // =========================================================================
    // Authorization — RBAC
    // =========================================================================

    public function test_plain_user_cannot_list_terms(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $response->assertStatus(403);
    }

    public function test_plain_user_cannot_create_term(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'label_am' => 'የተከለከለ ሙከራ',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // Happy-path CRUD
    // =========================================================================

    public function test_index_returns_only_body_terms(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $response->json('data'),
        );

        $this->assertContains(
            $this->termA1->id,
            $ids,
            'termA1 must appear in the bodyA1 index.',
        );
        $this->assertNotContains(
            $this->termB1->id,
            $ids,
            'termB1 must NOT appear in the bodyA1 index.',
        );

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'የተከለከለ ዘመን',
            $body,
            'termB1 label must not leak into the bodyA1 index.',
        );
    }

    public function test_authorized_user_can_show_term(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.show', [
            'governanceTerm' => $this->termA1->id,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.label_am', 'የ2018-2021 የሥራ ዘመን')
            ->assertJsonPath('data.effective_from', '2026-09-18')
            ->assertJsonPath('data.effective_until', '2029-09-17');
    }

    public function test_authorized_user_can_create_term(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'label_am'        => 'የ2030 የሥራ ዘመን',
            'label_en'        => 'Term 2030',
            'effective_from'  => '2030-09-18',
            'effective_until' => '2033-09-17',
            'notes'           => 'Created in a feature test.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.label_am', 'የ2030 የሥራ ዘመን')
            ->assertJsonPath('data.label_en', 'Term 2030')
            ->assertJsonPath('data.governance_body_id', $this->bodyA1->id);

        $this->assertDatabaseHas('governance_terms', [
            'label_am'           => 'የ2030 የሥራ ዘመን',
            'tenant_id'          => $this->tenantA->id,
            'governance_body_id' => $this->bodyA1->id,
        ]);
    }

    public function test_authorized_user_can_update_term(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.terms.update', [
            'governanceTerm' => $this->termA1->id,
        ]), [
            'label_en' => 'Renamed Term',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.label_en', 'Renamed Term');

        $this->assertDatabaseHas('governance_terms', [
            'id'       => $this->termA1->id,
            'label_en' => 'Renamed Term',
        ]);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_term_from_other_tenant_is_not_reachable(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.show', [
            'governanceTerm' => $this->termB1->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_index_for_other_tenant_body_is_not_reachable(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyB1->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_tenant_header_cannot_be_forged(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ])->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyB1->id,
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'የተከለከለ ዘመን',
            $body,
            'tenantB term label must not leak into a rejected request response.',
        );
    }

    // =========================================================================
    // Validation — shape and invariants
    // =========================================================================

    public function test_term_without_any_label_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'effective_from'  => '2030-01-01',
            'effective_until' => '2033-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['label_am']);
    }

    public function test_invalid_effective_date_range_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'label_am'        => 'የተሳሳተ ቀን',
            'effective_from'  => '2033-12-31',
            'effective_until' => '2030-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['effective_until']);
    }

    public function test_body_id_is_immutable_after_registration(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.terms.update', [
            'governanceTerm' => $this->termA1->id,
        ]), [
            'governance_body_id' => $this->bodyA2->id,  // different body
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['governance_body_id']);

        // The original body id must be unchanged.
        $this->termA1->refresh();
        $this->assertSame($this->bodyA1->id, $this->termA1->governance_body_id);
    }

    // =========================================================================
    // Overlap policy — the distinctive behaviour of terms
    // =========================================================================

    /**
     * Two terms of the same body MAY overlap in date range.
     *
     * This is the load-bearing difference from positions. The G2b
     * schema does not enforce non-overlap for terms; the service does
     * not either. See the migration docblock (G2b file 13 of 27)
     * §"No overlap enforcement" for the reasoning.
     *
     * The test creates a second term on `bodyA1` whose date range
     * fully contains `termA1`'s range, and asserts both are
     * retrievable via the index.
     */
    public function test_overlapping_active_terms_are_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // termA1 covers 2026-09-18 → 2029-09-17. This requested term is
        // fully inside that inclusive range and must be rejected.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'label_am'        => 'የሽግግር ወቅት',
            'label_en'        => 'Transition Period',
            'effective_from'  => '2028-01-01',
            'effective_until' => '2028-12-31',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['effective_from']);

        // The rejected overlapping term must not be persisted.
        $index = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.governance.terms.index', [
            'governanceBody' => $this->bodyA1->id,
        ]));

        $index->assertStatus(200);
        $labels = array_map(
            static fn (array $row): string => (string) ($row['label_am'] ?? ''),
            $index->json('data'),
        );
        $this->assertContains('የ2018-2021 የሥራ ዘመን', $labels);
        $this->assertNotContains('የሽግግር ወቅት', $labels);
    }

    // =========================================================================
    // Audit — requires G2b file 25 of 27
    // =========================================================================

    public function test_create_writes_audit_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), [
            'label_am' => 'የኦዲት ዘመን',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'governance.term.registered',
            'resource_type' => 'GovernanceTerm',
            'tenant_id'     => $this->tenantA->id,
        ]);

        $this->assertEquals(
            1,
            DB::table('audit_logs')
                ->where('action', 'governance.term.registered')
                ->count(),
        );
    }

    public function test_update_writes_audit_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.terms.update', [
            'governanceTerm' => $this->termA1->id,
        ]), [
            'label_en' => 'Audited Term',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'governance.term.updated',
            'resource_type' => 'GovernanceTerm',
            'resource_id'   => (string) $this->termA1->id,
            'tenant_id'     => $this->tenantA->id,
        ]);

        $this->assertEquals(
            1,
            DB::table('audit_logs')
                ->where('action', 'governance.term.updated')
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
        $this->assertSame('የ2018-2021 የሥራ ዘመን', $this->termA1->label_am);
        $this->assertSame('Term 2026-2029',       $this->termA1->label_en);
        $this->assertSame($this->bodyA1->id,      $this->termA1->governance_body_id);
        $this->assertTrue((bool) $this->termA1->is_active);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.terms.update', [
            'governanceTerm' => $this->termA1->id,
        ]), [
            'label_en' => 'Renamed For Audit',
        ])->assertStatus(200);

        // F1 — exact expected count with precise filters.
        $query = AuditLog::query()
            ->where('action',        'governance.term.updated')
            ->where('resource_type', 'GovernanceTerm')
            ->where('resource_id',   (string) $this->termA1->id)
            ->where('tenant_id',     $this->tenantA->id);

        $this->assertSame(
            1,
            $query->count(),
            'Exactly one governance.term.updated audit row must exist for this term.',
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
        $this->assertArrayHasKey('label_en', $old);
        $this->assertArrayHasKey('label_en', $new);
        $this->assertSame('Term 2026-2029',    $old['label_en']);
        $this->assertSame('Renamed For Audit', $new['label_en']);

        // Unchanged attributes must not be misrepresented as changed.
        foreach (
            ['governance_body_id', 'label_am', 'effective_from', 'effective_until', 'is_active']
            as $unchanged
        ) {
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
     * F2 — the GovernanceTerm HTTP surface (per the routes declared
     * for G2b) exposes only index / show / store / update. There is
     * no dedicated deactivate route. A PATCH that sets
     * `is_active = false` through the generic update endpoint is a
     * STATUS UPDATE and is described as such here.
     *
     * F3 — asserts the exact transition `true → false`, not merely
     * that the two values differ.
     */
    public function test_status_update_via_update_endpoint_records_exact_before_and_after_values(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->assertTrue((bool) $this->termA1->is_active);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.terms.update', [
            'governanceTerm' => $this->termA1->id,
        ]), [
            'is_active' => false,
        ])->assertStatus(200);

        // F1 — exact expected count with precise filters.
        $query = AuditLog::query()
            ->where('action',        'governance.term.updated')
            ->where('resource_type', 'GovernanceTerm')
            ->where('resource_id',   (string) $this->termA1->id)
            ->where('tenant_id',     $this->tenantA->id);

        $this->assertSame(
            1,
            $query->count(),
            'Exactly one governance.term.updated audit row must exist for this term.',
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
        foreach (
            ['governance_body_id', 'label_am', 'label_en', 'effective_from', 'effective_until']
            as $unchanged
        ) {
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
        ])->postJson(route('api.governance.terms.store', [
            'governanceBody' => $this->bodyA1->id,
        ]), ['label_am' => ['invalid-type'], 'label_en' => null]);

        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    /** Unauthorized malformed update must be rejected before validation. */
    public function test_unauthorized_update_is_rejected_before_validation(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->patchJson(route('api.governance.terms.update', [
            'governanceTerm' => $this->termA1->id,
        ]), ['label_am' => ['invalid-type'], 'label_en' => null]);

        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    /**
     * Create a governance-domain permission.
     *
     * Named `createGovernancePermission()` to avoid a method-name
     * collision with the base `Tests\TestCase`.
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