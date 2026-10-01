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
 * CertificateTest
 *
 * Feature tests for the share-certificate HTTP surface.
 *
 * =============================================================================
 * Endpoints covered
 * =============================================================================
 *   GET  /api/shareholders/certificates?shareholder_id=X      index()   list
 *   GET  /api/shareholders/certificates/{certificate}         show()    single
 *   POST /api/shareholders/certificates                        store()   issue
 *   POST /api/shareholders/certificates/{certificate}/cancel   cancel()  cancel
 *
 * =============================================================================
 * RefreshDatabase + memory hygiene
 * =============================================================================
 * The suite does not assert on `DB::afterCommit` events (the audit listener
 * is fire-and-forget), so RefreshDatabase is sufficient. `gc_collect_cycles()`
 * runs at both ends of every test to keep the file within the Plesk CLI PHP
 * memory limit — same discipline as 2A.46 and 2A.47.
 *
 * =============================================================================
 * Tenant context for FormRequest validation
 * =============================================================================
 * The `StoreCertificateRequest` (2A.20) validates `shareholder_id` with a
 * tenant-scoped `exists` rule that dereferences the currently-resolved
 * tenant. In production that value is bound by the `tenant.membership`
 * middleware. In this test suite we replicate the binding explicitly:
 *
 *   1. `forceFill` on the admin's `default_tenant_id` (bypasses $fillable
 *      so the value survives even if the User model does not expose it).
 *   2. `app()->instance('tenant', …)` and `app()->instance('mases.tenant', …)`
 *      so that any helper (`mases_tenant_id()`, `mases_tenant()`) used inside
 *      FormRequest::rules() resolves to the same tenant the fixtures use.
 *
 * This mirrors the middleware's behaviour without coupling the test to the
 * middleware implementation. See the "FIX" section of the 2A.48 review.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createShareholderPermission()` — not `createPermission()` — to avoid the
 * collision with the protected method declared on Tests\TestCase.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature
 * @version 1.1.0
 */
final class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $shareholder;
    private ShareClass $shareClass;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'      => 'main-tenant',
            'name_am'   => 'ዋና ቴናንት',
            'name_en'   => 'Main Tenant',
            'domain'    => 'main.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->otherTenant = Tenant::create([
            'slug'      => 'other-tenant',
            'name_am'   => 'ሌላ ቴናንት',
            'name_en'   => 'Other Tenant',
            'domain'    => 'other.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin-' . uniqid() . '@example.test',
        ]);
        // forceFill bypasses $fillable — the factory's override for
        // `default_tenant_id` is dropped if the column is guarded, which
        // would leave FormRequest tenant-scoped rules resolving to null.
        $this->admin->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email' => 'noperm-' . uniqid() . '@example.test',
        ]);
        $this->userWithoutPermission->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->createShareholderPermission('shareholder.view',   'View shareholders');
        $this->createShareholderPermission('shareholder.update', 'Update shareholders');
        $this->createShareholderPermission('certificate.issue',  'Issue certificates');
        $this->createShareholderPermission('certificate.cancel', 'Cancel certificates');

        $this->admin->load('permissions');

        $this->shareholder = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'father_name_am'   => 'አየለ',
            'is_active'        => true,
        ]);

        $this->shareClass = ShareClass::create([
            'code'      => 'STANDARD',
            'name_am'   => 'መደበኛ',
            'name_en'   => 'Standard',
            'par_value' => '10000.0000',
            'currency'  => 'ETB',
            'is_active' => true,
        ]);

        // Bind the tenant into the container so `mases_tenant_id()` —
        // and any FormRequest rule that dereferences it — resolves to the
        // same tenant the fixtures were written against. The production
        // tenant.membership middleware performs this binding during a live
        // HTTP request; the test replicates it here.
        $this->bindTenantContext();

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // index() — GET /api/shareholders/certificates?shareholder_id=X
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.index', [
            'shareholder_id' => $this->shareholder->id,
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_index_requires_shareholder_id_parameter(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.index'));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['shareholder_id']]);
    }

    public function test_index_returns_certificates_for_shareholder(): void
    {
        $this->makeCertificate($this->shareholder, 'CERT-001');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.index', [
            'shareholder_id' => $this->shareholder->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'shareholder_id', 'share_class_id', 'status', 'is_active'],
            ],
        ]);
        $response->assertJsonFragment([
            'certificate_number' => 'CERT-001',
            'status'             => 'issued',
        ]);
    }

    public function test_index_returns_404_for_cross_tenant_shareholder(): void
    {
        $foreign = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሌላ',
            'is_active'        => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.index', [
            'shareholder_id' => $foreign->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // show() — GET /api/shareholders/certificates/{certificate}
    // =========================================================================

    public function test_show_requires_authentication(): void
    {
        $certificate = $this->makeCertificate($this->shareholder, 'CERT-002');

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.show', [
            'certificate' => $certificate->id,
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_show_returns_certificate(): void
    {
        $certificate = $this->makeCertificate($this->shareholder, 'CERT-003');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.show', [
            'certificate' => $certificate->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $certificate->id);
        $response->assertJsonPath('data.certificate_number', 'CERT-003');
        $response->assertJsonPath('data.status', 'issued');
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.is_terminal', false);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'shareholder_id',
                'share_class_id',
                'share_holding_id',
                'certificate_number',
                'status',
                'status_label',
                'is_active',
                'is_terminal',
                'is_draft',
                'was_replaced',
                'issued_at',
            ],
        ]);

        // §3.1 item 6 — status_label and issued_at must be present on the
        // envelope (they are translation-key strings at this stage, pending
        // CR-009).
        $this->assertNotNull($response->json('data.status_label'));
        $this->assertNotNull($response->json('data.issued_at'));
    }

    public function test_show_returns_404_for_missing_certificate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.show', [
            'certificate' => 999999,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_show_returns_404_for_cross_tenant_certificate(): void
    {
        $foreignShareholder = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሌላ',
            'is_active'        => true,
        ]);
        $foreign = $this->makeCertificate($foreignShareholder, 'CERT-X');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.certificates.show', [
            'certificate' => $foreign->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // store() — POST /api/shareholders/certificates
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id' => $this->shareholder->id,
            'share_class_id' => $this->shareClass->id,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_issue_permission(): void
    {
        $this->actingAsAdminInTenant($this->userWithoutPermission);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id' => $this->shareholder->id,
            'share_class_id' => $this->shareClass->id,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_store_issues_certificate(): void
    {
        $holding = ShareHolding::create([
            'shareholder_id'   => $this->shareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '145.0000',
        ]);

        $this->actingAsAdminInTenant();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id'     => $this->shareholder->id,
            'share_class_id'     => $this->shareClass->id,
            'share_holding_id'   => $holding->id,
            'certificate_number' => 'CERT-100',
            'notes'              => 'Initial issuance.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.certificate_number', 'CERT-100');
        $response->assertJsonPath('data.status', 'issued');
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.shareholder_id', $this->shareholder->id);
        $response->assertJsonPath('data.share_class_id', $this->shareClass->id);
        $response->assertJsonPath('data.share_holding_id', $holding->id);

        $this->assertDatabaseHas('share_certificates', [
            'certificate_number' => 'CERT-100',
            'status'             => 'issued',
        ]);
    }

    public function test_store_creates_certificate_for_pair(): void
    {
        // No holding yet for this (shareholder, share_class) pair.
        $this->assertSame(
            0,
            ShareHolding::where('shareholder_id', $this->shareholder->id)
                ->where('share_class_id', $this->shareClass->id)
                ->count(),
        );

        $this->actingAsAdminInTenant();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id' => $this->shareholder->id,
            'share_class_id' => $this->shareClass->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'issued');
        // The service auto-created a holding for the pair.
        $this->assertSame(
            1,
            ShareHolding::where('shareholder_id', $this->shareholder->id)
                ->where('share_class_id', $this->shareClass->id)
                ->count(),
        );
    }

    public function test_store_rejects_duplicate_certificate_number(): void
    {
        $this->makeCertificate($this->shareholder, 'CERT-DUP');

        // A second holder avoids the "one active certificate per holding"
        // rule firing first — we want the number-uniqueness check to be the
        // stated reason for rejection.
        $second = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሁለተኛ',
            'is_active'        => true,
        ]);

        $this->actingAsAdminInTenant();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id'     => $second->id,
            'share_class_id'     => $this->shareClass->id,
            'certificate_number' => 'CERT-DUP',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['certificate_number']]);
    }

    public function test_store_rejects_second_active_certificate_for_holding(): void
    {
        $holding = ShareHolding::create([
            'shareholder_id'   => $this->shareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '145.0000',
        ]);

        $this->actingAsAdminInTenant();

        // First issuance succeeds.
        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id'     => $this->shareholder->id,
            'share_class_id'     => $this->shareClass->id,
            'share_holding_id'   => $holding->id,
            'certificate_number' => 'CERT-A',
        ])->assertStatus(201);

        // Second issuance for the same holding fails.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.store'), [
            'shareholder_id'     => $this->shareholder->id,
            'share_class_id'     => $this->shareClass->id,
            'share_holding_id'   => $holding->id,
            'certificate_number' => 'CERT-B',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['share_holding_id']]);
    }

    // =========================================================================
    // cancel() — POST /api/shareholders/certificates/{certificate}/cancel
    // =========================================================================

    public function test_cancel_requires_authentication(): void
    {
        $certificate = $this->makeCertificate($this->shareholder, 'CERT-C1');

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $certificate->id,
        ]), [
            'reason' => 'Test cancellation.',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_cancel_requires_cancel_permission(): void
    {
        $certificate = $this->makeCertificate($this->shareholder, 'CERT-C2');

        $this->actingAsAdminInTenant($this->userWithoutPermission);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $certificate->id,
        ]), [
            'reason' => 'Test cancellation.',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_cancel_transitions_issued_to_cancelled(): void
    {
        $certificate = $this->makeCertificate($this->shareholder, 'CERT-C3');

        $this->actingAsAdminInTenant();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $certificate->id,
        ]), [
            'reason' => 'Customer request.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'cancelled');
        $response->assertJsonPath('data.is_active', false);
        $response->assertJsonPath('data.is_terminal', true);

        // §3.1 item 13 — cancelled_at must be exposed on the envelope.
        $this->assertNotNull($response->json('data.cancelled_at'));

        $certificate->refresh();
        $this->assertSame('cancelled', $certificate->status->value);
        $this->assertNotNull($certificate->cancelled_at);

        // The service appends the reason to the notes field.
        $this->assertStringContainsString('Customer request.', (string) $certificate->notes);
    }

    public function test_cancel_rejects_terminal_state(): void
    {
        $certificate = $this->makeCertificate($this->shareholder, 'CERT-C4');

        $this->actingAsAdminInTenant();

        // First cancellation succeeds.
        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $certificate->id,
        ]))->assertStatus(200);

        // Second cancellation on the same certificate is rejected because
        // the certificate is already in a terminal state.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $certificate->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_cancel_returns_404_for_cross_tenant_certificate(): void
    {
        $foreignShareholder = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሌላ',
            'is_active'        => true,
        ]);
        $foreign = $this->makeCertificate($foreignShareholder, 'CERT-X2');

        $this->actingAsAdminInTenant();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.certificates.cancel', [
            'certificate' => $foreign->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Authenticate as the given user AND re-bind the tenant context.
     *
     * The tenant.membership middleware performs the container binding in
     * production. The test replicates it here so that FormRequest rules
     * which dereference the currently-resolved tenant (`mases_tenant_id()`)
     * see the same value the fixtures use.
     *
     * Call this *after* `$this->actingAs(...)` — it does not authenticate
     * on its own; it only ensures the tenant binding is fresh for the
     * upcoming request.
     */
    private function actingAsAdminInTenant(?User $user = null): void
    {
        $user ??= $this->admin;

        Sanctum::actingAs($user, ['*']);

        $this->bindTenantContext();
    }

    /**
     * Bind the primary tenant into the service container under every
     * conventional name the codebase may use for `mases_tenant_id()`.
     *
     * Safe to call multiple times — `app()->instance()` overwrites.
     */
    private function bindTenantContext(): void
    {
        $this->app->instance('tenant', $this->tenant);
        $this->app->instance('mases.tenant', $this->tenant);
        $this->app->instance('current_tenant', $this->tenant);
    }

    /**
     * Create a ShareHolding for the given shareholder/class pair and issue
     * an `issued` ShareCertificate against it, bypassing HTTP. Fixtures
     * only — HTTP behavior is asserted by the tests above.
     */
    private function makeCertificate(Shareholder $shareholder, ?string $number): ShareCertificate
    {
        $holding = ShareHolding::create([
            'shareholder_id'   => $shareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '100.0000',
        ]);

        return ShareCertificate::create([
            'shareholder_id'     => $shareholder->id,
            'share_class_id'     => $this->shareClass->id,
            'share_holding_id'   => $holding->id,
            'certificate_number' => $number,
            'status'             => 'issued',
            'issued_at'          => now(),
        ]);
    }

    /**
     * Create a Shareholder-domain permission and attach it to the admin user.
     *
     * Named `createShareholderPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase.
     */
    private function createShareholderPermission(string $slug, string $label): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => null,
            'name_en'        => $label,
            'description_am' => null,
            'description_en' => $label,
            'category'       => 'certificate',
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