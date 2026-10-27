<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Services\PersonIdentityService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonIdentityContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function clean_schema_contains_additive_nullable_person_links(): void
    {
        $this->assertTrue(Schema::hasTable('people'));
        $this->assertTrue(Schema::hasTable('person_tenant_accesses'));
        $this->assertTrue(Schema::hasColumn('people', 'public_id'));
        $this->assertTrue(Schema::hasColumn('people', 'user_id'));

        foreach (['employees', 'shareholders', 'governance_memberships'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} table.");
            $this->assertTrue(Schema::hasColumn($table, 'person_id'), "Expected {$table}.person_id.");
        }

        $this->assertFalse(Schema::hasTable('person_identity_claims'), 'No unapproved identity claims are created.');
    }

    #[Test]
    public function person_receives_a_stable_uuid_and_rejects_uuid_mutation(): void
    {
        $person = Person::query()->create([
            'first_name_am' => 'ሀና',
            'first_name_en' => 'Hana',
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $person->public_id,
        );

        $this->expectException(DomainException::class);
        $person->public_id = '00000000-0000-4000-8000-000000000000';
        $person->save();
    }

    #[Test]
    public function person_uuid_is_not_mass_assignable_and_account_link_is_not_a_profile_attribute(): void
    {
        $person = new Person();

        $this->assertNotContains('public_id', $person->getFillable());
        $this->assertNotContains('user_id', $person->getFillable());
    }

    #[Test]
    public function database_prevents_one_user_from_being_currently_linked_to_two_people(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $first = Person::query()->create(['first_name_en' => 'Hana']);
        $second = Person::query()->create(['first_name_en' => 'Mekdes']);

        $first->user_id = $user->getKey();
        $first->save();

        $second->user_id = $user->getKey();

        $this->expectException(QueryException::class);
        $second->save();
    }

    #[Test]
    public function database_prevents_duplicate_person_tenant_visibility_grants(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $person = Person::query()->create(['first_name_en' => 'Hana']);
        $user = User::factory()->create(['is_active' => true]);

        $grant = [
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'created_by' => $user->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('person_tenant_accesses')->insert($grant);

        $this->expectException(QueryException::class);
        DB::table('person_tenant_accesses')->insert($grant);
    }

    #[Test]
    public function identity_mutations_fail_closed_without_explicit_people_permission(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->tenants()->attach($tenant->id, [
            'joined_at' => now(),
            'status' => 'active',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('people.manage');

        app(PersonIdentityService::class)->create(
            (int) $tenant->id,
            $actor,
            ['first_name_en' => 'Hana'],
        );
    }
}
