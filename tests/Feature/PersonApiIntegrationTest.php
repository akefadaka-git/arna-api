<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Files\Models\File;
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

    #[Test]
    public function tenant_people_manager_can_update_profile_with_a_reason_and_cannot_erase_every_name(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_am' => 'ሀና',
            'first_name_en' => 'Hana',
        ])->assertCreated();
        $publicId = $created->json('data.public_id');

        $this->withHeaders($headers)->putJson('/api/people/'.$publicId, [
            'first_name_am' => 'ሀና አዲስ',
            'reason' => 'Corrected the registered Amharic name',
        ])->assertOk()
            ->assertJsonPath('data.first_name_am', 'ሀና አዲስ')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.tenant_id');

        $this->withHeaders($headers)->putJson('/api/people/'.$publicId, [
            'first_name_am' => null,
            'first_name_en' => null,
            'father_name_am' => null,
            'father_name_en' => null,
            'grandfather_name_am' => null,
            'grandfather_name_en' => null,
            'reason' => 'Attempt to remove all names',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('people', [
            'public_id' => $publicId,
            'first_name_am' => 'ሀና አዲስ',
        ]);
    }

    #[Test]
    public function people_manager_can_read_only_the_relationship_summary_for_a_visible_person(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_am' => 'ሚካኤል',
        ])->assertCreated();
        $publicId = $created->json('data.public_id');

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_linked', false)
            ->assertJsonPath('data.employment_count', 0)
            ->assertJsonPath('data.shareholding_count', 0)
            ->assertJsonPath('data.governance_membership_count', 0)
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.tenant_id');
    }

    #[Test]
    public function tenant_people_manager_cannot_link_a_user_account_without_global_account_link_permission(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        $targetUser = User::factory()->create(['is_active' => true]);
        $targetUser->tenants()->attach($tenant->getKey(), [
            'joined_at' => now(),
            'status' => 'active',
        ]);
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Account Link Test',
        ])->assertCreated();
        $publicId = $created->json('data.public_id');

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/account', [
            'user_id' => $targetUser->getKey(),
            'reason' => 'Test unauthorized account linking',
        ])->assertForbidden();

        $this->assertDatabaseHas('people', [
            'public_id' => $publicId,
            'user_id' => null,
        ]);
    }

    #[Test]
    public function people_manager_can_link_list_and_unlink_a_file_they_are_authorized_to_view(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $role = Role::query()->where('slug', 'tenant-people-manager-'.$tenant->getKey())->firstOrFail();
        $filePermission = Permission::query()->create([
            'name_en' => 'View Files',
            'slug' => 'file.view',
            'category' => 'file',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach($filePermission->getKey(), ['assigned_at' => now()]);

        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Document Profile',
        ])->assertCreated();
        $publicId = $created->json('data.public_id');
        $personId = Person::query()->where('public_id', $publicId)->value('id');
        $file = File::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $actor->getKey(),
            'original_name' => 'identity-proof.pdf',
            'display_name_am' => 'የማንነት ማረጋገጫ',
            'display_name_en' => 'Identity proof',
            'name' => 'test-identity-proof.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 128,
            'path' => 'tests/identity-proof.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/documents', [
            'file_id' => $file->getKey(),
            'reason' => 'Attach identity proof to the profile',
        ])->assertOk()->assertJsonPath('data.linked', true);

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/documents')
            ->assertOk()
            ->assertJsonPath('data.0.file_id', $file->getKey())
            ->assertJsonPath('data.0.display_name_en', 'Identity proof')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissingPath('data.0.tenant_id')
            ->assertJsonMissingPath('data.0.user_id');

        $this->withHeaders($headers)->deleteJson('/api/people/'.$publicId.'/documents/'.$file->getKey(), [
            'reason' => 'Detach the document from this profile',
        ])->assertOk()->assertJsonPath('data.linked', false);

        $this->assertDatabaseMissing('person_document_links', [
            'tenant_id' => $tenant->getKey(),
            'person_id' => $personId,
            'file_id' => $file->getKey(),
        ]);
    }

    #[Test]
    public function person_document_listing_hides_files_without_file_view_permission(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];
        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Private Document Profile',
        ])->assertCreated();
        $publicId = $created->json('data.public_id');
        $personId = Person::query()->where('public_id', $publicId)->value('id');
        $file = File::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $actor->getKey(),
            'original_name' => 'private.pdf',
            'name' => 'private.pdf',
            'size' => 64,
            'path' => 'tests/private.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);
        DB::table('person_document_links')->insert([
            'tenant_id' => $tenant->getKey(),
            'person_id' => $personId,
            'file_id' => $file->getKey(),
            'linked_by' => $actor->getKey(),
            'link_reason' => 'Pre-existing restricted document link',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/documents')
            ->assertOk()->assertExactJson(['success' => true, 'data' => []]);
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
