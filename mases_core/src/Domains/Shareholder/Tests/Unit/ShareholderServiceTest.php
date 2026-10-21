<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareholderRegistered;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareholderUpdated;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * ShareholderServiceTest
 *
 * Unit tests for ShareholderService (2A.12).
 *
 * =============================================================================
 * What this file covers
 * =============================================================================
 *   register():
 *     - Individual registration with an Amharic name.
 *     - Individual registration with an English name.
 *     - Individual registration with no name at all → 422.
 *     - Individual registration with an entity-name field → the field is
 *       silently dropped (see note below).
 *     - Legal-entity registration with a representative → success.
 *     - Legal-entity registration without an entity name → 422.
 *     - Legal-entity registration without a representative → 422.
 *     - Legal-entity registration with a direct user_id → 422.
 *     - Legal-entity registration with an individual name field → the
 *       field is silently dropped (see note below).
 *     - Registration with an unknown shareholder_type → 422.
 *     - Registration with a missing shareholder_type → 422.
 *     - Duplicate user link within a tenant → 422.
 *
 *   update() / deactivate() / reactivate() / assignRepresentative() /
 *   clearRepresentative(): see the individual test method docblocks.
 *
 * =============================================================================
 * Note: silent drop vs. strict rejection
 * =============================================================================
 * The service SILENTLY DROPS name columns that do not match the
 * shareholder type. An individual payload containing `entity_name_am` is
 * accepted; the entity-name columns are unset from the attributes before
 * persistence, so the row ends up with `entity_name_am = null`.
 *
 * The stricter behaviour — refuse such a payload with a 422 — lives in
 * the HTTP request layer (StoreShareholderRequest, 2A.16;
 * UpdateShareholderRequest, 2A.17) and is covered by the future
 * ShareholderRegistryTest (2A.46). The two layers intentionally differ:
 * the service tolerates because it is called from multiple contexts
 * (HTTP, Artisan, tests); the request rejects because HTTP callers
 * deserve an explicit error.
 *
 * =============================================================================
 * A fresh in-memory database per test, not RefreshDatabase
 * =============================================================================
 * The service dispatches its events via `DB::afterCommit(...)`. Under
 * `RefreshDatabase`, the framework's per-test transaction never commits,
 * so the after-commit callbacks never fire and `Event::fake()` cannot
 * observe the dispatch. A fresh database per test avoids the wrapping
 * transaction, allowing the service's own `DB::transaction()` to be the
 * outermost one. The trade-off is per-test migration time; the suite is
 * small (25 tests), so this is acceptable.
 *
 * =============================================================================
 * Service construction
 * =============================================================================
 * ShareholderServiceProvider (2A.2) binds ShareholderService and
 * ShareholderRepository as singletons, and — since CR-017 / A14 — the
 * provider is registered in bootstrap/providers.php. The tests still
 * construct the service manually via `new ShareholderService(new
 * ShareholderRepository())` so that they remain independent of the
 * container's registration state. This is the correct pattern for a unit
 * test: it isolates the service from the framework's bootstrap.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Tests\Unit
 */
final class ShareholderServiceTest extends TestCase
{

    private ShareholderService $service;
    private Tenant $tenant;
    private User $linkedUser;
    private User $representativeUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Rebuild the isolated test database without rolling back identifier history.
        $this->artisan('migrate:fresh');
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);

        $this->service = new ShareholderService(new ShareholderRepository());

        $this->tenant = Tenant::create([
            'slug'      => 'test-tenant',
            'name_am'   => 'የሙከራ ቴናንት',
            'name_en'   => 'Test Tenant',
            'domain'    => 'test.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->linkedUser = User::factory()->create([
            'email' => 'linked-user-' . uniqid() . '@example.test',
        ]);

        $this->representativeUser = User::factory()->create([
            'email' => 'representative-' . uniqid() . '@example.test',
        ]);
    }

    public function test_register_fails_closed_when_legacy_shareholder_number_is_unreconciled(): void
    {
        Shareholder::create([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Legacy holder',
            'is_active' => true,
        ]);

        $this->expectException(\LogicException::class);

        $this->service->register([
            'shareholder_type' => 'individual',
            'first_name_en' => 'New holder',
        ], $this->tenant->id);
    }

    // =========================================================================
    // register() — individual, positive paths
    // =========================================================================

    public function test_register_individual_with_amharic_first_name_succeeds(): void
    {
        Event::fake([ShareholderRegistered::class]);

        $shareholder = $this->service->register([
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'father_name_am'   => 'አየለ',
        ], $this->tenant->id);

        $this->assertInstanceOf(Shareholder::class, $shareholder);
        $this->assertSame($this->tenant->id, $shareholder->tenant_id);
        $this->assertSame('individual', $shareholder->shareholder_type);
        $this->assertSame('አቀፋ', $shareholder->first_name_am);
        $this->assertSame('አየለ', $shareholder->father_name_am);
        $this->assertNull($shareholder->first_name_en);
        $this->assertTrue($shareholder->is_active);

        Event::assertDispatched(
            ShareholderRegistered::class,
            fn (ShareholderRegistered $event): bool => $event->shareholder->id === $shareholder->id
        );
    }

    public function test_register_individual_with_english_first_name_succeeds(): void
    {
        Event::fake([ShareholderRegistered::class]);

        $shareholder = $this->service->register([
            'shareholder_type' => 'individual',
            'first_name_en'    => 'Akefa',
        ], $this->tenant->id);

        $this->assertSame('Akefa', $shareholder->first_name_en);
        $this->assertNull($shareholder->first_name_am);

        Event::assertDispatched(ShareholderRegistered::class);
    }

    // =========================================================================
    // register() — individual, negative paths
    // =========================================================================

    public function test_register_individual_without_any_name_throws(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'shareholder_type' => 'individual',
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('first_name_am', $e->errors());
            throw $e;
        }
    }

    public function test_register_individual_with_entity_name_ignores_entity_field(): void
    {
        Event::fake([ShareholderRegistered::class]);

        // The payload includes entity-name fields on an individual. The
        // service silently drops them — they are meaningless on an
        // individual row. The stricter rejection is the HTTP request
        // layer's job (StoreShareholderRequest, 2A.16).
        $shareholder = $this->service->register([
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'entity_name_am'   => 'የኩባንያ ስም',
            'entity_name_en'   => 'Company Name',
        ], $this->tenant->id);

        $this->assertSame('individual', $shareholder->shareholder_type);
        $this->assertSame('አቀፋ', $shareholder->first_name_am);
        $this->assertNull($shareholder->entity_name_am);
        $this->assertNull($shareholder->entity_name_en);

        Event::assertDispatched(ShareholderRegistered::class);
    }

    // =========================================================================
    // register() — legal entity, positive paths
    // =========================================================================

    public function test_register_legal_entity_with_representative_succeeds(): void
    {
        Event::fake([ShareholderRegistered::class]);

        $shareholder = $this->service->register([
            'shareholder_type'       => 'company',
            'entity_name_am'         => 'የሙከራ ኩባንያ',
            'entity_name_en'         => 'Test Company',
            'representative_user_id' => $this->representativeUser->id,
        ], $this->tenant->id);

        $this->assertSame('company', $shareholder->shareholder_type);
        $this->assertSame('የሙከራ ኩባንያ', $shareholder->entity_name_am);
        $this->assertSame($this->representativeUser->id, $shareholder->representative_user_id);
        $this->assertNull($shareholder->user_id);
        $this->assertNull($shareholder->first_name_am);
        $this->assertNull($shareholder->first_name_en);

        Event::assertDispatched(ShareholderRegistered::class);
    }

    // =========================================================================
    // register() — legal entity, negative paths
    // =========================================================================

    public function test_register_legal_entity_without_entity_name_throws(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'shareholder_type'       => 'company',
                'representative_user_id' => $this->representativeUser->id,
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entity_name_am', $e->errors());
            throw $e;
        }
    }

    public function test_register_legal_entity_without_representative_throws(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'shareholder_type' => 'company',
                'entity_name_en'   => 'Test Company',
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('representative_user_id', $e->errors());
            throw $e;
        }
    }

    public function test_register_legal_entity_with_direct_user_id_throws(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'shareholder_type'       => 'company',
                'entity_name_en'         => 'Test Company',
                'representative_user_id' => $this->representativeUser->id,
                'user_id'                => $this->linkedUser->id,
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
            throw $e;
        }
    }

    public function test_register_legal_entity_with_individual_name_ignores_individual_fields(): void
    {
        Event::fake([ShareholderRegistered::class]);

        // The payload includes individual-name fields on a company. The
        // service silently drops them — they are meaningless on a legal-
        // entity row. The stricter rejection is the HTTP request layer's
        // job (StoreShareholderRequest, 2A.16).
        $shareholder = $this->service->register([
            'shareholder_type'       => 'company',
            'first_name_am'          => 'አቀፋ',
            'first_name_en'          => 'Akefa',
            'father_name_am'         => 'አየለ',
            'entity_name_am'         => 'የሙከራ ኩባንያ',
            'entity_name_en'         => 'Test Company',
            'representative_user_id' => $this->representativeUser->id,
        ], $this->tenant->id);

        $this->assertSame('company', $shareholder->shareholder_type);
        $this->assertSame('የሙከራ ኩባንያ', $shareholder->entity_name_am);
        $this->assertSame('Test Company', $shareholder->entity_name_en);
        $this->assertNull($shareholder->first_name_am);
        $this->assertNull($shareholder->first_name_en);
        $this->assertNull($shareholder->father_name_am);

        Event::assertDispatched(ShareholderRegistered::class);
    }

    // =========================================================================
    // register() — type validation
    // =========================================================================

    public function test_register_with_unknown_type_throws(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'shareholder_type' => 'alien',
                'first_name_am'    => 'X',
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shareholder_type', $e->errors());
            throw $e;
        }
    }

    public function test_register_with_missing_type_throws(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'first_name_am' => 'X',
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shareholder_type', $e->errors());
            throw $e;
        }
    }

    // =========================================================================
    // register() — user link uniqueness
    // =========================================================================

    public function test_register_individual_with_already_linked_user_throws(): void
    {
        $this->service->register([
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'user_id'          => $this->linkedUser->id,
        ], $this->tenant->id);

        $this->expectException(ValidationException::class);

        try {
            $this->service->register([
                'shareholder_type' => 'individual',
                'first_name_am'    => 'ሌላ',
                'user_id'          => $this->linkedUser->id,
            ], $this->tenant->id);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
            throw $e;
        }
    }

    // =========================================================================
    // update() — type immutability
    // =========================================================================

    public function test_update_with_different_type_throws(): void
    {
        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->update($shareholder, [
                'shareholder_type' => 'company',
            ]);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shareholder_type', $e->errors());
            throw $e;
        }
    }

    public function test_update_with_same_type_is_silently_accepted(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);

        $updated = $this->service->update($shareholder, [
            'shareholder_type' => 'individual',
            'first_name_en'    => 'Akefa',
        ]);

        $this->assertSame('individual', $updated->shareholder_type);
        $this->assertSame('Akefa', $updated->first_name_en);

        Event::assertDispatched(ShareholderUpdated::class);
    }

    // =========================================================================
    // deactivate() / reactivate()
    // =========================================================================

    public function test_deactivate_sets_flag_and_dispatches_event(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);
        $this->assertTrue($shareholder->is_active);

        $updated = $this->service->deactivate($shareholder);

        $this->assertFalse($updated->is_active);

        Event::assertDispatched(ShareholderUpdated::class);
    }

    public function test_deactivate_on_already_inactive_is_noop(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);
        $this->service->deactivate($shareholder);

        Event::fake([ShareholderUpdated::class]);

        $unchanged = $this->service->deactivate($shareholder->fresh());

        $this->assertFalse($unchanged->is_active);
        Event::assertNotDispatched(ShareholderUpdated::class);
    }

    public function test_reactivate_sets_flag_and_dispatches_event(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);
        $this->service->deactivate($shareholder);

        Event::fake([ShareholderUpdated::class]);

        $reactivated = $this->service->reactivate($shareholder->fresh());

        $this->assertTrue($reactivated->is_active);
        Event::assertDispatched(ShareholderUpdated::class);
    }

    public function test_reactivate_on_already_active_is_noop(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);

        $this->service->reactivate($shareholder);

        Event::assertNotDispatched(ShareholderUpdated::class);
    }

    // =========================================================================
    // assignRepresentative()
    // =========================================================================

    public function test_assign_representative_to_legal_entity_succeeds(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createLegalEntity(
            representative: $this->representativeUser
        );

        $secondRepresentative = User::factory()->create([
            'email' => 'second-rep-' . uniqid() . '@example.test',
        ]);

        $updated = $this->service->assignRepresentative(
            $shareholder,
            $secondRepresentative
        );

        $this->assertSame($secondRepresentative->id, $updated->representative_user_id);
        Event::assertDispatched(ShareholderUpdated::class);
    }

    public function test_assign_representative_to_individual_succeeds(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);

        $updated = $this->service->assignRepresentative(
            $shareholder,
            $this->representativeUser
        );

        $this->assertSame($this->representativeUser->id, $updated->representative_user_id);
        Event::assertDispatched(ShareholderUpdated::class);
    }

    public function test_assign_representative_with_unpersisted_user_throws(): void
    {
        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);

        $unpersisted = new User([
            'email' => 'ghost@example.test',
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->assignRepresentative($shareholder, $unpersisted);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('representative_user_id', $e->errors());
            throw $e;
        }
    }

    public function test_assign_representative_with_same_user_is_noop(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createLegalEntity(
            representative: $this->representativeUser
        );

        $this->service->assignRepresentative($shareholder, $this->representativeUser);

        Event::assertNotDispatched(ShareholderUpdated::class);
    }

    // =========================================================================
    // clearRepresentative()
    // =========================================================================

    public function test_clear_representative_on_legal_entity_throws(): void
    {
        $shareholder = $this->createLegalEntity(
            representative: $this->representativeUser
        );

        $this->expectException(ValidationException::class);

        try {
            $this->service->clearRepresentative($shareholder);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('representative_user_id', $e->errors());
            throw $e;
        }
    }

    public function test_clear_representative_on_individual_succeeds(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);
        $this->service->assignRepresentative($shareholder, $this->representativeUser);

        $updated = $this->service->clearRepresentative($shareholder->fresh());

        $this->assertNull($updated->representative_user_id);
        Event::assertDispatched(ShareholderUpdated::class);
    }

    public function test_clear_representative_when_none_assigned_is_noop(): void
    {
        Event::fake([ShareholderUpdated::class]);

        $shareholder = $this->createIndividual(['first_name_am' => 'አቀፋ']);

        $this->service->clearRepresentative($shareholder);

        Event::assertNotDispatched(ShareholderUpdated::class);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function createIndividual(array $extra = []): Shareholder
    {
        return $this->service->register(
            array_merge([
                'shareholder_type' => 'individual',
                'first_name_am'    => 'አቀፋ',
                'father_name_am'   => 'አየለ',
            ], $extra),
            $this->tenant->id,
        );
    }

    private function createLegalEntity(?User $representative = null): Shareholder
    {
        $representative ??= $this->representativeUser;

        return $this->service->register([
            'shareholder_type'       => 'company',
            'entity_name_am'         => 'የሙከራ ኩባንያ',
            'entity_name_en'         => 'Test Company',
            'representative_user_id' => $representative->id,
        ], $this->tenant->id);
    }
}