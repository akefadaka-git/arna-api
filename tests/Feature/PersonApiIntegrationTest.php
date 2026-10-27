<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_member_with_tenant_scoped_permission_can_create_and_read_visible_person(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);

        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];
        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_am' => 'ሀና',
            'first_name_en' => 'Hana',
            'public_id' => '00000000-0000-4000-8000-000000000000',
            'user_id' => 999999,
            'tenant_id' => 999999,
        ]);

        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.first_name_en', 'Hana')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.tenant_id');

        $publicId = $created->json('data.public_id');
        $this->withHeaders($headers)->getJson('/api/people/'.$publicId)
            ->assertOk()
            ->assertJsonPath('data.public_id', $publicId);

        $this->assertDatabaseHas('person_tenant_accesses', [
            'person_id' => Person::query()->where('public_id', $publicId)->value('id'),
            'tenant_id' => $tenant->getKey(),
            'status' => 'active',
        ]);
    }

    #[Test]
    public function person_api_does_not_disclose_profiles_without_an_active_tenant_grant(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $person = Person::query()->create(['first_name_en' => 'Private']);

        Sanctum::actingAs($actor);
        $this->withHeader('X-Tenant-ID', (string) $tenant->getKey())
            ->getJson('/api/people/'.$person->public_id)
            ->assertNotFound();

        $this->assertDatabaseMissing('person_tenant_accesses', [
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => 'active',
        ]);
        $this->assertNotSame($tenant->getKey(), $otherTenant->getKey());
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantPeopleManager(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->tenants()->attach($tenant->getKey(), [
            'joined_at' => now(),
            'status' => 'active',
        ]);

        $permission = Permission::query()->create([
            'name_en' => 'Manage People Profiles',
            'slug' => 'people.manage',
            'category' => 'user',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role = Role::query()->create([
            'name_en' => 'Tenant People Manager',
            'slug' => 'tenant-people-manager-'.$tenant->getKey(),
            'scope' => 'tenant',
            'tenant_id' => $tenant->getKey(),
            'is_active' => true,
        ]);
        $role->permissions()->attach($permission->getKey(), ['assigned_at' => now()]);
        $actor->roles()->attach($role->getKey(), ['assigned_at' => now()]);

        return [$tenant, $actor];
    }
}
