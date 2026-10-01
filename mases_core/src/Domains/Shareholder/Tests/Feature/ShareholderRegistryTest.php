<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * ShareholderRegistryTest
 *
 * Feature tests for the Shareholder HTTP surface.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations (CR-019 rev 3)
 * =============================================================================
 * This file does not assert on domain events, so the after-commit caveat
 * that keeps the two unit suites (2A.44, 2A.45) on DatabaseMigrations does
 * not apply here. RefreshDatabase migrates once per file and wraps each
 * test in a transaction, eliminating the cumulative memory cost that was
 * terminating the process under Plesk's CLI PHP handler.
 *
 * =============================================================================
 * Nested resource paths (CR-020 rev 2)
 * =============================================================================
 * ShareholderResource (2A.25) exposes the six individual name columns
 * under a nested `individual_name` object, and the two entity name columns
 * under `entity_name`. Top-level `first_name_am` does not exist. Test
 * assertions for individual name fields therefore use:
 *
 *     data.individual_name.first_name_am
 *     data.individual_name.first_name_en
 *     ...
 *
 * =============================================================================
 * 404 error codes (CR-020)
 * =============================================================================
 * Laravel 11+ converts ModelNotFoundException to NotFoundHttpException
 * inside Illuminate\Foundation\Exceptions\Handler::prepareException(),
 * before the renderable closure in bootstrap/app.php runs. The renderer's
 * ModelNotFoundException branch is therefore unreachable in normal flow,
 * and every 404 — implicit binding failure, explicit controller throw —
 * arrives as NotFoundHttpException and renders as ROUTE_NOT_FOUND.
 *
 * The two 404 tests assert the envelope shape (status, success:false,
 * code in {RESOURCE_NOT_FOUND, ROUTE_NOT_FOUND}) rather than a specific
 * code, since the code is a routing-layer detail.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createShareholderPermission()` — not `createPermission()` — to avoid the
 * name collision with the base Tests\TestCase.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature
 */
final class ShareholderRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $individualShareholder;

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
            'email'             => 'admin-' . uniqid() . '@example.test',
            'default_tenant_id' => $this->tenant->id,
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email'             => 'unauthorized-' . uniqid() . '@example.test',
            'default_tenant_id' => $this->tenant->id,
        ]);
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->createShareholderPermission('shareholder.view',   'View shareholders');
        $this->createShareholderPermission('shareholder.create', 'Create shareholder');
        $this->createShareholderPermission('shareholder.update', 'Update shareholder');

        $this->admin->load('permissions');

        $this->individualShareholder = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'father_name_am'   => 'አየለ',
            'is_active'        => true,
        ]);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson(route('api.shareholders.index'));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_index_returns_403_when_user_lacks_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_index_returns_paginated_shareholders(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'tenant_id', 'shareholder_type', 'full_name', 'display_name'],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

        $response->assertJsonFragment([
            'id' => $this->individualShareholder->id,
        ]);
    }

    // =========================================================================
    // store()
    // =========================================================================

    public function test_store_creates_individual_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.store'), [
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሙከራ',
            'father_name_am'   => 'ተፈራ',
            'is_active'        => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.shareholder_type', 'individual');

        // The six individual name columns are nested under `individual_name`
        // in ShareholderResource (2A.25). See the class docblock.
        $response->assertJsonPath('data.individual_name.first_name_am', 'ሙከራ');
        $response->assertJsonPath('data.individual_name.father_name_am', 'ተፈራ');
    }

    public function test_store_rejects_individual_without_any_name(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.store'), [
            'shareholder_type' => 'individual',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['first_name_am']]);
    }

    public function test_store_rejects_individual_with_entity_name(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.store'), [
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'entity_name_am'   => 'የኩባንያ ስም',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['entity_name_am']]);
    }

    public function test_store_rejects_legal_entity_without_representative(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.store'), [
            'shareholder_type' => 'company',
            'entity_name_en'   => 'Test Company',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['representative_user_id']]);
    }

    public function test_store_requires_create_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.store'), [
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሙከራ',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    // =========================================================================
    // show()
    // =========================================================================

    public function test_show_returns_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.show', [
            'shareholder' => $this->individualShareholder->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $this->individualShareholder->id);
        $response->assertJsonPath('data.shareholder_type', 'individual');
        $response->assertJsonPath('data.individual_name.first_name_am', 'አቀፋ');
    }

    public function test_show_returns_404_for_missing_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.show', [
            'shareholder' => 999999,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // Laravel 11+ converts ModelNotFoundException to NotFoundHttpException
        // before the renderer runs; the code is a routing-layer detail. Accept
        // either canonical 404 code.
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
            'Expected a 404 envelope code from the canonical renderer.',
        );
    }

    public function test_show_returns_404_for_cross_tenant_shareholder(): void
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
        ])->getJson(route('api.shareholders.show', [
            'shareholder' => $foreign->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
            'Expected a 404 envelope code from the canonical renderer.',
        );
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function test_update_modifies_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson(route('api.shareholders.update', [
            'shareholder' => $this->individualShareholder->id,
        ]), [
            'first_name_en' => 'Akefa',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.individual_name.first_name_en', 'Akefa');

        $this->assertSame(
            'Akefa',
            Shareholder::find($this->individualShareholder->id)->first_name_en,
        );
    }

    public function test_update_rejects_type_change(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson(route('api.shareholders.update', [
            'shareholder' => $this->individualShareholder->id,
        ]), [
            'shareholder_type' => 'company',
            'first_name_en'    => 'Changed',
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            'individual',
            Shareholder::find($this->individualShareholder->id)->shareholder_type,
        );
    }

    public function test_update_requires_update_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson(route('api.shareholders.update', [
            'shareholder' => $this->individualShareholder->id,
        ]), [
            'first_name_en' => 'Akefa',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Create a Shareholder-domain permission and assign it to the admin user.
     *
     * @param string $slug
     * @param string $label
     * @return Permission
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