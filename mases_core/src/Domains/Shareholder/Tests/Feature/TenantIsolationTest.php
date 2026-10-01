<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * TenantIsolationTest (Shareholder domain)
 *
 * Verifies that the Shareholder domain does not leak data across tenant
 * boundaries. This file is DISTINCT from the Phase 1 suite at
 * `tests/Feature/TenantIsolationTest.php`, which tests the tenancy
 * middleware layer in general. This file is scoped to the Shareholder
 * domain and exercises the four HTTP surfaces that read or write
 * tenant-scoped data:
 *
 *   /shareholders                            (ShareholderController, 2A.21)
 *   /shareholders/holdings                   (ShareHoldingController, 2A.23)
 *   /shareholders/holdings/entries           (ShareHoldingController::store)
 *   /shareholders/certificates               (CertificateController, 2A.24)
 *   /shareholders/{shareholder}              (update path)
 *
 * =============================================================================
 * Enforcement point
 * =============================================================================
 * Every cross-tenant read or write in this domain flows through
 * `ShareholderRepository::findForTenant($tenantId, $id)` (2A.11) or through
 * the controller's `assertBelongsToTenant()` helper (CertificateController,
 * 2A.24). Both produce a `ModelNotFoundException` when the row exists but
 * belongs to a different tenant, and both produce the same 404 a
 * non-existent id would produce — no information leak about existence.
 *
 * The tenant id itself is resolved server-side by `mases_tenant_id()`,
 * never from client input. The `X-Tenant-ID` header is trusted only after
 * the `tenant.membership` middleware has verified the authenticated user
 * belongs to that tenant (test 10 below).
 *
 * =============================================================================
 * Fixture topology
 * =============================================================================
 *   tenantA  — the caller's tenant. The admin user is a member.
 *   tenantB  — the foreign tenant. The admin user is NOT a member.
 *
 *   shareholderA  — in tenantA.
 *   shareholderB  — in tenantB.
 *
 *   holdingA  — in tenantA (belongs to shareholderA).
 *   holdingB  — in tenantB (belongs to shareholderB).
 *
 *   certificateA  — in tenantA (belongs to shareholderA).
 *   certificateB  — in tenantB (belongs to shareholderB).
 *
 * The `shareClass` is reference data and is not tenant-scoped. Both
 * tenants share the single STANDARD row used in setUp().
 *
 * =============================================================================
 * Raw-body scan policy
 * =============================================================================
 * Where a test scans the raw response body for evidence of cross-tenant
 * leakage, the scan target is the shareholder's Amharic given name — a
 * semantically meaningful, unambiguously-scoped marker. Numeric ids are
 * NOT used as scan targets: a single-digit id collides with timestamps,
 * UUIDs, and exception line numbers, producing false positives (as
 * discovered in iteration 1 of this suite).
 *
 * =============================================================================
 * RefreshDatabase + memory hygiene
 * =============================================================================
 * Same discipline as 2A.46, 2A.47, and 2A.48: RefreshDatabase, with
 * `gc_collect_cycles()` at both ends of every test to stay inside the
 * Plesk CLI PHP memory limit.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createShareholderPermission()` — not `createPermission()` — to avoid
 * the protected method on Tests\TestCase. Same helper name used by
 * 2A.46, 2A.47, and 2A.48, so the suite is grep-consistent.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature
 * @version 1.2.0
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $admin;
    private Shareholder $shareholderA;
    private Shareholder $shareholderB;
    private ShareHolding $holdingA;
    private ShareHolding $holdingB;
    private ShareCertificate $certificateA;
    private ShareCertificate $certificateB;
    private ShareClass $shareClass;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenantA = Tenant::create([
            'slug'      => 'tenant-a',
            'name_am'   => 'ቴናንት ኤ',
            'name_en'   => 'Tenant A',
            'domain'    => 'a.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'      => 'tenant-b',
            'name_am'   => 'ቴናንት ቢ',
            'name_en'   => 'Tenant B',
            'domain'    => 'b.maalearna.com.et',
            'is_active' => true,
        ]);

        // The admin is a member of tenantA only. `forceFill` bypasses
        // $fillable so the default-tenant override survives regardless of
        // the User model's mass-assignment guards.
        $this->admin = User::factory()->create([
            'email' => 'iso-admin-' . uniqid() . '@example.test',
        ]);
        $this->admin->forceFill(['default_tenant_id' => $this->tenantA->id])->save();
        $this->admin->tenants()->attach($this->tenantA->id, ['joined_at' => now()]);

        // Permissions the admin needs to reach every controller under test.
        // Slugs match the route middleware declared in Routes/api.php (2A.36):
        //   - share_holding.entry  (holdings/entries POST)
        //   - certificate.issue    (certificates POST)
        //   - certificate.cancel   (certificates/{id}/cancel POST)
        //   - shareholder.view     (shareholders GET)
        //   - shareholder.create   (shareholders POST)
        //   - shareholder.update   (shareholders/{id} PUT/PATCH)
        $this->createShareholderPermission('shareholder.view',    'View shareholders');
        $this->createShareholderPermission('shareholder.create',  'Create shareholders');
        $this->createShareholderPermission('shareholder.update',  'Update shareholders');
        $this->createShareholderPermission('share_holding.entry', 'Record holding entries');
        $this->createShareholderPermission('certificate.issue',   'Issue certificates');
        $this->createShareholderPermission('certificate.cancel',  'Cancel certificates');

        $this->admin->load('permissions');

        // Reference data — shared by both tenants.
        $this->shareClass = ShareClass::create([
            'code'      => 'STANDARD',
            'name_am'   => 'መደበኛ',
            'name_en'   => 'Standard',
            'par_value' => '10000.0000',
            'currency'  => 'ETB',
            'is_active' => true,
        ]);

        // Shareholder in tenantA.
        $this->shareholderA = Shareholder::create([
            'tenant_id'        => $this->tenantA->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        // Shareholder in tenantB. Its Amharic given name (ሳሙኤል) is used
        // throughout this suite as the semantic leak marker in raw-body
        // scans — it is unambiguously tied to tenantB and cannot collide
        // with timestamps, UUIDs, or exception line numbers.
        $this->shareholderB = Shareholder::create([
            'tenant_id'        => $this->tenantB->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        // Holdings for both shareholders.
        $this->holdingA = ShareHolding::create([
            'shareholder_id'   => $this->shareholderA->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '100.0000',
        ]);

        $this->holdingB = ShareHolding::create([
            'shareholder_id'   => $this->shareholderB->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '250.0000',
        ]);

        // Certificates for both shareholders. Created directly (fixture
        // path) to avoid the service's "one active per holding" invariant
        // interfering with the cross-tenant reads.
        $this->certificateA = ShareCertificate::create([
            'shareholder_id'     => $this->shareholderA->id,
            'share_class_id'     => $this->shareClass->id,
            'share_holding_id'   => $this->holdingA->id,
            'certificate_number' => 'ISO-A-001',
            'status'             => 'issued',
            'issued_at'          => now(),
        ]);

        $this->certificateB = ShareCertificate::create([
            'shareholder_id'     => $this->shareholderB->id,
            'share_class_id'     => $this->shareClass->id,
            'share_holding_id'   => $this->holdingB->id,
            'certificate_number' => 'ISO-B-001',
            'status'             => 'issued',
            'issued_at'          => now(),
        ]);

        // Bind tenantA into the container so FormRequest rules that
        // dereference `mases_tenant_id()` see the same tenant the fixtures
        // were written against. Same pattern used by 2A.48.
        $this->bindTenantContext($this->tenantA);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Test 1 — index returns only tenant-scoped shareholders
    // =========================================================================

    public function test_index_returns_only_tenant_shareholders(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.shareholders.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data, 'Index payload must be an array under `data`.');

        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $data,
        );

        // tenantA's shareholder appears; tenantB's does not.
        $this->assertContains(
            $this->shareholderA->id,
            $ids,
            'tenantA shareholder must appear in the index for tenantA.',
        );
        $this->assertNotContains(
            $this->shareholderB->id,
            $ids,
            'tenantB shareholder must NOT appear in the index for tenantA.',
        );

        // Semantic marker check: tenantB's Amharic given name must not
        // appear anywhere in the raw response body. Numeric ids are not
        // used as scan targets — a single-digit id collides with
        // timestamps, UUIDs, and exception line numbers.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB shareholder name must not appear in tenantA index response.',
        );
    }

    // =========================================================================
    // Test 2 — show returns 404 for a cross-tenant shareholder
    // =========================================================================

    public function test_show_returns_404_for_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.shareholders.show', [
            'shareholder' => $this->shareholderB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Test 3 — update returns 404 for a cross-tenant shareholder
    // =========================================================================

    public function test_update_returns_404_for_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->putJson(route('api.shareholders.update', [
            'shareholder' => $this->shareholderB->id,
        ]), [
            'first_name_am' => 'የተለወጠ',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // tenantB's shareholder must be unchanged.
        $this->shareholderB->refresh();
        $this->assertNotSame('የተለወጠ', $this->shareholderB->first_name_am);
    }

    // =========================================================================
    // Test 4 — holdings index rejects a cross-tenant shareholder filter
    // =========================================================================

    public function test_shareholding_index_returns_404_for_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.shareholders.holdings.index', [
            'shareholder_id' => $this->shareholderB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Test 5 — holding show rejects a cross-tenant holding
    // =========================================================================
    //
    // Route parameter is named `shareHolding` (camelCase), matching Laravel's
    // auto-generated binding name for the ShareHolding model. Verified by
    // the UrlGenerationException in the iteration-1 run:
    //     URI: shareholders/holdings/{shareHolding}
    //                [Missing parameter: shareHolding]

    public function test_shareholding_show_returns_404_for_cross_tenant_holding(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.shareholders.holdings.show', [
            'shareHolding' => $this->holdingB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Test 6 — holding store rejects a cross-tenant shareholder
    // =========================================================================
    //
    // Route name resolved from Routes/api.php (2A.36):
    //
    //     Route::post('/entries', [ShareHoldingController::class, 'store'])
    //         ->middleware('auth.permission:share_holding.entry')
    //         ->name('entries.store');
    //
    // Fully-qualified name: `api.shareholders.holdings.entries.store`.
    // The URI (with the provider's `shareholders` prefix) is
    // `/shareholders/holdings/entries`. The admin fixture holds the
    // `share_holding.entry` permission (see setUp) — the route middleware
    // is satisfied, allowing the controller's tenant-scoping check to be
    // the reason for the 404.

    public function test_shareholding_store_returns_404_for_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'shareholder_id' => $this->shareholderB->id,
            'share_class_id' => $this->shareClass->id,
            'direction'      => 'in',
            'quantity'       => 5,
            'entry_type'     => 'subscription',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // No entry may have been written against tenantB's holding.
        $this->assertDatabaseMissing('share_holding_entries', [
            'share_holding_id' => $this->holdingB->id,
        ]);
    }

    // =========================================================================
    // Test 7 — certificates index rejects a cross-tenant shareholder filter
    // =========================================================================

    public function test_certificate_index_returns_404_for_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.shareholders.certificates.index', [
            'shareholder_id' => $this->shareholderB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Test 8 — certificate show rejects a cross-tenant certificate
    // =========================================================================

    public function test_certificate_show_returns_404_for_cross_tenant_certificate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.shareholders.certificates.show', [
            'certificate' => $this->certificateB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Test 9 — certificate cancel rejects a cross-tenant certificate
    // =========================================================================

    public function test_certificate_cancel_returns_404_for_cross_tenant_certificate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $this->certificateB->id,
        ]), [
            'reason' => 'Attempted cross-tenant cancellation.',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The certificate must not have been modified.
        $this->certificateB->refresh();
        $this->assertSame('issued', $this->certificateB->status->value);
        $this->assertNull($this->certificateB->cancelled_at);
    }

    // =========================================================================
    // Test 10 — forged X-Tenant-ID header is rejected by tenant.membership
    // =========================================================================

    public function test_tenant_header_cannot_be_forged_to_access_other_tenant(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // The admin is a member of tenantA only. Sending tenantB's id in
        // the X-Tenant-ID header attempts to elevate context. The
        // TenantMembershipMiddleware resolves the header via
        // `mases_tenant_id()`, checks membership, and rejects with 403
        // TENANT_ACCESS_DENIED before any controller or permission check
        // runs.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ])->getJson(route('api.shareholders.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // A rejected cross-tenant request must not return a data payload.
        // The response is an error envelope only; `data` must be null.
        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );

        // Semantic leak check: tenantB's shareholder name must not appear
        // in the response body. Numeric ids are deliberately not used as
        // scan targets — a single-digit id collides with timestamps,
        // UUIDs, and exception line numbers, producing false positives.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB shareholder name must not leak into a rejected request response.',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Bind a tenant into the service container under every conventional
     * name the domain uses for `mases_tenant_id()` and `mases_tenant()`.
     *
     * Safe to call multiple times — `app()->instance()` overwrites.
     */
    private function bindTenantContext(Tenant $tenant): void
    {
        $this->app->instance('tenant', $tenant);
        $this->app->instance('mases.tenant', $tenant);
        $this->app->instance('current_tenant', $tenant);
    }

    /**
     * Create a Shareholder-domain permission and attach it to the admin.
     *
     * Named `createShareholderPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase. Same
     * helper name used by 2A.46, 2A.47, and 2A.48.
     */
    private function createShareholderPermission(string $slug, string $label): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => null,
            'name_en'        => $label,
            'description_am' => null,
            'description_en' => $label,
            'category'       => 'shareholder',
            'scope'          => 'tenant',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
            'is_system'      => true,
        ]);

        $this->admin->permissions()->attach($permission->id, ['assigned_at' => now()]);

        return $permission;
    }
}