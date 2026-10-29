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

    #[Test]
    public function people_manager_can_discover_only_eligible_relationship_candidates_in_the_current_tenant(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Candidate Profile',
        ])->assertCreated();
        $publicId = $created->json('data.public_id');

        // Fixture an already-validated account association so the individual
        // shareholder obeys ShareholderService's required user_id invariant.
        $shareholderUser = User::factory()->create(['is_active' => true]);
        $shareholderUser->tenants()->attach($tenant->getKey(), [
            'joined_at' => now(),
            'status' => 'active',
        ]);
        DB::table('people')->where('public_id', $publicId)->update(['user_id' => $shareholderUser->getKey()]);

        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'employee_number' => 'EMP-TEST-001',
            'user_id' => null,
            'person_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $duplicateEmployeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'employee_number' => 'EMP-TEST-002',
            'user_id' => null,
            'person_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactiveEmployeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'employee_number' => 'EMP-INACTIVE-001',
            'user_id' => null,
            'person_id' => null,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'tenant_id' => $otherTenant->getKey(),
            'employee_number' => 'EMP-OTHER-001',
            'user_id' => null,
            'person_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shareholderId = DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'shareholder_number' => 'SH-TEST-001',
            'shareholder_type' => 'individual',
            'first_name_am' => 'ማርታ',
            'first_name_en' => 'Marta',
            'user_id' => $shareholderUser->getKey(),
            'representative_user_id' => null,
            'person_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invalidShareholderId = DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'shareholder_number' => 'SH-INVALID-001',
            'shareholder_type' => 'individual',
            'first_name_am' => 'ያልተገናኘ',
            'first_name_en' => 'Invalid Individual',
            'user_id' => null,
            'representative_user_id' => null,
            'person_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bodyId = DB::table('governance_bodies')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'code' => 'TEST-BOARD',
            'body_type' => 'board',
            'name_am' => 'የሙከራ ቦርድ',
            'name_en' => 'Test Board',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('governance_positions')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'governance_body_id' => $bodyId,
            'code' => 'TEST-MEMBER',
            'name_am' => 'አባል',
            'name_en' => 'Member',
            'is_voting' => true,
            'is_officer' => false,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $membershipId = DB::table('governance_memberships')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'governance_body_id' => $bodyId,
            'governance_position_id' => $positionId,
            'user_id' => null,
            'member_name_am' => 'የሙከራ አባል',
            'member_name_en' => 'Test Member',
            'external_reference' => 'GOV-TEST-001',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'is_ex_officio' => false,
            'person_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $overlapMembershipId = DB::table('governance_memberships')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'governance_body_id' => $bodyId,
            'governance_position_id' => $positionId,
            'user_id' => null,
            'member_name_am' => 'ሌላ አባል',
            'member_name_en' => 'Different Member Name',
            'external_reference' => 'GOV-TEST-002',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'is_ex_officio' => false,
            'person_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inactiveMembershipId = DB::table('governance_memberships')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'governance_body_id' => $bodyId,
            'governance_position_id' => $positionId,
            'user_id' => null,
            'member_name_en' => 'Suspended Member',
            'external_reference' => 'GOV-SUSPENDED-001',
            'start_date' => now()->toDateString(),
            'status' => 'suspended',
            'is_ex_officio' => false,
            'person_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeOptions = $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships/employees/options')
            ->assertOk()
            ->assertJsonPath('data.domain', 'employees')
            ->assertJsonPath('data.candidates.0.label', 'EMP-TEST-001')
            ->assertJsonMissingPath('data.candidates.0.user_id');
        $this->assertStringNotContainsString('EMP-OTHER-001', $employeeOptions->getContent());

        $shareholderOptions = $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships/shareholders/options')
            ->assertOk()
            ->assertJsonPath('data.candidates.0.label', 'ማርታ')
            ->assertJsonMissingPath('data.candidates.0.user_id');
        $this->assertStringNotContainsString('SH-INVALID-001', $shareholderOptions->getContent());
        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/shareholders/'.$invalidShareholderId, [
            'reason' => 'Reject individual shareholder without direct account',
        ])->assertForbidden();

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships/governance-memberships/options')
            ->assertOk()
            ->assertJsonPath('data.candidates.0.label', 'የሙከራ አባል')
            ->assertJsonPath('data.candidates.0.detail', 'GOV-TEST-001')
            ->assertJsonMissingPath('data.candidates.0.user_id');
        $personId = (int) Person::query()->where('public_id', $publicId)->value('id');

        foreach ([
            ['employees', $employeeId, 'Employee relationship'],
            ['shareholders', $shareholderId, 'Shareholder relationship'],
            ['governance-memberships', $membershipId, 'Governance relationship'],
        ] as [$domain, $recordId, $reason]) {
            $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/'.$domain.'/'.$recordId, [
                'reason' => 'Link '.$reason.' to canonical Person',
            ])->assertOk()->assertJsonPath('data.linked', true);
        }

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/employees/'.$duplicateEmployeeId, [
            'reason' => 'Reject duplicate active employee identity',
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/employees/'.$inactiveEmployeeId, [
            'reason' => 'Reject inactive employee identity link',
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/governance-memberships/'.$inactiveMembershipId, [
            'reason' => 'Reject suspended membership as a new link candidate',
        ])->assertForbidden();

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/governance-memberships/'.$overlapMembershipId, [
            'reason' => 'Reject overlapping governance identity',
        ])->assertForbidden();

        // Linked records must remain visible as lifecycle history even after
        // they cease to be candidates for a new association.
        DB::table('employees')->where('id', $employeeId)->update(['is_active' => false]);
        DB::table('shareholders')->where('id', $shareholderId)->update(['is_active' => false]);
        DB::table('governance_memberships')->where('id', $membershipId)->update(['status' => 'suspended']);
        $softDeletedEmployeeId = (int) DB::table('employees')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'employee_number' => 'EMP-DELETED-001',
            'user_id' => null,
            'person_id' => $personId,
            'is_active' => false,
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships/employees/options')
            ->assertOk()
            ->assertJsonPath('data.linked.0.id', $employeeId)
            ->assertJsonPath('data.linked.0.status', 'inactive')
            ->assertJsonPath('data.candidates.0.label', 'EMP-TEST-002')
            ->assertJsonMissing(['id' => $softDeletedEmployeeId]);
        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships/shareholders/options')
            ->assertOk()
            ->assertJsonPath('data.linked.0.id', $shareholderId)
            ->assertJsonPath('data.linked.0.status', 'inactive');
        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/relationships/governance-memberships/options')
            ->assertOk()
            ->assertJsonPath('data.linked.0.id', $membershipId)
            ->assertJsonPath('data.linked.0.status', 'suspended')
            ->assertJsonPath('data.candidates.0.label', 'ሌላ አባል');

        $accountLinkPermission = Permission::query()->create([
            'name_en' => 'Manage Global Person Account Links',
            'slug' => 'people.account-link.manage',
            'category' => 'user',
            'scope' => 'global',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $actor->permissions()->attach($accountLinkPermission->getKey(), ['assigned_at' => now()]);
        $this->withHeaders($headers)->deleteJson('/api/people/'.$publicId.'/account', [
            'reason' => 'Cannot unlink account while shareholder link exists',
        ])->assertForbidden();
        $this->assertDatabaseHas('people', ['id' => $personId, 'user_id' => $shareholderUser->getKey()]);

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'tenant_id' => $tenant->getKey(), 'person_id' => $personId]);
        $this->assertDatabaseHas('shareholders', ['id' => $shareholderId, 'tenant_id' => $tenant->getKey(), 'person_id' => $personId]);
        $this->assertDatabaseHas('governance_memberships', ['id' => $membershipId, 'tenant_id' => $tenant->getKey(), 'person_id' => $personId]);

        // A valid manager must not unlink a domain record by supplying a
        // different Person's public ID in the URL. The service verifies the
        // record's current person_id against the target Person under lock.
        $otherPersonResponse = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Different Person',
        ])->assertCreated();
        $otherPublicId = $otherPersonResponse->json('data.public_id');

        foreach ([
            ['employees', $employeeId],
            ['shareholders', $shareholderId],
            ['governance-memberships', $membershipId],
        ] as [$domain, $recordId]) {
            $this->withHeaders($headers)->postJson('/api/people/'.$otherPublicId.'/relationships/'.$domain.'/'.$recordId.'/unlink', [
                'reason' => 'Reject unlink against a different Person',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'tenant_id' => $tenant->getKey(), 'person_id' => $personId]);
        $this->assertDatabaseHas('shareholders', ['id' => $shareholderId, 'tenant_id' => $tenant->getKey(), 'person_id' => $personId]);
        $this->assertDatabaseHas('governance_memberships', ['id' => $membershipId, 'tenant_id' => $tenant->getKey(), 'person_id' => $personId]);

        foreach ([
            ['employees', $employeeId, 'Employee relationship'],
            ['shareholders', $shareholderId, 'Shareholder relationship'],
            ['governance-memberships', $membershipId, 'Governance relationship'],
        ] as [$domain, $recordId, $reason]) {
            $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/relationships/'.$domain.'/'.$recordId.'/unlink', [
                'reason' => 'Unlink '.$reason.' from canonical Person',
            ])->assertOk()->assertJsonPath('data.linked', false);
        }

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'tenant_id' => $tenant->getKey(), 'person_id' => null]);
        $this->assertDatabaseHas('shareholders', ['id' => $shareholderId, 'tenant_id' => $tenant->getKey(), 'person_id' => null]);
        $this->assertDatabaseHas('governance_memberships', ['id' => $membershipId, 'tenant_id' => $tenant->getKey(), 'person_id' => null]);
    }

    #[Test]
    public function directory_search_is_paginated_and_remains_scoped_to_the_active_tenant_grant(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        foreach ([
            ['first_name_am' => 'ሀና', 'first_name_en' => 'Hana'],
            ['first_name_am' => 'ሚካኤል', 'first_name_en' => 'Michael'],
            ['first_name_en' => 'Hana Private'],
        ] as $attributes) {
            $this->withHeaders($headers)->postJson('/api/people', $attributes)->assertCreated();
        }

        $this->withHeaders($headers)->getJson('/api/people?search=Hana&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name_en', 'Hana');

        $this->withHeaders($headers)->getJson('/api/people?search=%E1%88%80%E1%8A%93')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.first_name_am', 'ሀና');

        $this->withHeaders($headers)->getJson('/api/people?search=NoMatchingPerson')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)->getJson('/api/people?search='.str_repeat('x', 101))
            ->assertUnprocessable();

        $this->withHeaders($headers)->getJson('/api/people?per_page=101')
            ->assertUnprocessable();
    }

    #[Test]
    public function people_manager_can_manage_encrypted_contact_points_without_exposing_raw_values(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $person = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Hana',
        ])->assertCreated();
        $publicId = $person->json('data.public_id');

        $created = $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/contacts', [
            'contact_type' => 'email',
            'label' => 'Primary email',
            'contact_value' => 'hana@example.test',
            'is_primary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.contact_type', 'email')
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonMissing(['contact_value' => 'hana@example.test'])
            ->assertJsonMissing(['masked_value' => 'hana@example.test']);

        $contactId = $created->json('data.id');
        $storedCiphertext = DB::table('person_contact_points')->where('id', $contactId)->value('contact_value');
        $this->assertNotSame('hana@example.test', $storedCiphertext);

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contactId)
            ->assertJsonPath('data.0.is_verified', false)
            ->assertJsonMissing(['contact_value' => 'hana@example.test']);

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/contacts/'.$contactId.'/deactivate', [
            'reason' => 'Contact details are no longer current',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('person_contact_points', [
            'id' => $contactId,
            'status' => 'inactive',
            'deactivation_reason' => 'Contact details are no longer current',
        ]);
    }

    #[Test]
    public function phone_contact_duplicate_detection_ignores_formatting_punctuation(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $person = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Phone Duplicate Check',
        ])->assertCreated();
        $publicId = $person->json('data.public_id');

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/contacts', [
            'contact_type' => 'phone',
            'contact_value' => '+251 (911) 234-567',
            'is_primary' => true,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/contacts', [
            'contact_type' => 'phone',
            'contact_value' => '+251911234567',
            'is_primary' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('person_contact_points', 1);
    }

    #[Test]
    public function people_services_reject_writes_after_person_tenant_access_is_revoked(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $firstResponse = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Revoked Access Person',
        ])->assertCreated();
        $secondResponse = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Still Visible Person',
        ])->assertCreated();
        $person = Person::query()->where('public_id', $firstResponse->json('data.public_id'))->firstOrFail();
        $related = Person::query()->where('public_id', $secondResponse->json('data.public_id'))->firstOrFail();

        DB::table('person_tenant_accesses')
            ->where('tenant_id', $tenant->getKey())
            ->where('person_id', $person->getKey())
            ->update(['status' => 'revoked', 'updated_at' => now()]);

        $assertNotFound = function (callable $operation): void {
            try {
                $operation();
                $this->fail('A People service must reject writes after tenant access is revoked.');
            } catch (\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        };

        $assertNotFound(fn () => app(\\MaaleArna\\MASES\\Core\\Domains\\People\\Services\\PersonContactPointService::class)->create(
            (int) $tenant->getKey(),
            $person,
            ['contact_type' => 'email', 'contact_value' => 'revoked@example.test', 'is_primary' => false],
            (int) $actor->getKey(),
        ));
        $assertNotFound(fn () => app(\\MaaleArna\\MASES\\Core\\Domains\\People\\Services\\PersonParticipationService::class)->create(
            (int) $tenant->getKey(),
            $person,
            ['participation_type' => 'membership', 'title' => 'Must not persist', 'reason' => 'Testing revoked tenant access'],
            (int) $actor->getKey(),
        ));
        $assertNotFound(fn () => app(\\MaaleArna\\MASES\\Core\\Domains\\People\\Services\\PersonRelationshipService::class)->create(
            (int) $tenant->getKey(),
            $person,
            $related,
            ['relationship_type' => 'household_member', 'reason' => 'Testing revoked tenant access'],
            (int) $actor->getKey(),
        ));

        $this->assertDatabaseMissing('person_contact_points', [
            'tenant_id' => $tenant->getKey(),
            'person_id' => $person->getKey(),
        ]);
        $this->assertDatabaseMissing('person_participations', [
            'tenant_id' => $tenant->getKey(),
            'person_id' => $person->getKey(),
        ]);
        $this->assertDatabaseMissing('person_relationships', [
            'tenant_id' => $tenant->getKey(),
            'person_id' => $person->getKey(),
        ]);
    }

    #[Test]
    public function people_manager_can_create_only_tenant_visible_person_relationships_and_prevent_duplicates(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $person = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_am' => 'ሀና',
        ])->assertCreated();
        $related = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Michael',
        ])->assertCreated();

        $publicId = $person->json('data.public_id');
        $relatedId = $related->json('data.public_id');
        $payload = [
            'related_public_id' => $relatedId,
            'relationship_type' => 'guardian',
            'reason' => 'Confirmed by the authorized record keeper',
        ];

        $created = $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/person-relationships', $payload)
            ->assertCreated()
            ->assertJsonPath('data.relationship_type', 'guardian')
            ->assertJsonPath('data.related_public_id', $relatedId);

        $relationshipId = $created->json('data.id');
        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/person-relationships', $payload)
            ->assertStatus(409);

        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/person-relationships')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $relationshipId);

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/person-relationships/'.$relationshipId.'/deactivate', [
            'reason' => 'Relationship record was entered in error',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('person_relationships', [
            'id' => $relationshipId,
            'status' => 'inactive',
            'deactivation_reason' => 'Relationship record was entered in error',
        ]);
    }

    #[Test]
    public function people_manager_can_record_list_and_deactivate_tenant_scoped_participation(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];
        $person = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Participation Profile',
        ])->assertCreated();
        $publicId = $person->json('data.public_id');

        $created = $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/participations', [
            'participation_type' => 'ministry',
            'title' => 'Youth Ministry',
            'reference_code' => 'MIN-001',
            'effective_from' => '2026-01-01',
            'reason' => 'Record authorized ministry participation',
        ])->assertCreated()
            ->assertJsonPath('data.participation_type', 'ministry')
            ->assertJsonPath('data.title', 'Youth Ministry')
            ->assertJsonPath('data.status', 'active');

        $participationId = $created->json('data.id');
        $this->withHeaders($headers)->getJson('/api/people/'.$publicId.'/participations')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $participationId)
            ->assertJsonPath('data.0.reference_code', 'MIN-001');

        $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/participations/'.$participationId.'/deactivate', [
            'reason' => 'Participation has formally ended',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('person_participations', [
            'id' => $participationId,
            'tenant_id' => $tenant->getKey(),
            'status' => 'inactive',
            'deactivation_reason' => 'Participation has formally ended',
        ]);
    }

    #[Test]
    public function tenant_access_reactivation_preserves_original_grant_provenance(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];
        Sanctum::actingAs($actor);

        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Grant Provenance',
        ])->assertCreated();
        $person = Person::query()->where('public_id', $created->json('data.public_id'))->firstOrFail();

        $permission = Permission::query()->create([
            'name_en' => 'Manage Global Person Tenant Access',
            'slug' => 'people.tenant-access.manage',
            'category' => 'user',
            'scope' => 'global',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $actor->permissions()->attach($permission->getKey(), ['assigned_at' => now()]);

        $original = DB::table('person_tenant_accesses')
            ->where('person_id', $person->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->first();
        $this->assertNotNull($original);

        DB::table('person_tenant_accesses')
            ->where('person_id', $person->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->update(['status' => 'revoked', 'updated_at' => now()->subMinute()]);

        app(\MaaleArna\MASES\Core\Domains\People\Services\PersonIdentityService::class)
            ->grantTenantAccess($tenant->getKey(), $person->getKey(), $actor, 'Restore previously revoked access');

        $reactivated = DB::table('person_tenant_accesses')
            ->where('person_id', $person->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->first();

        $this->assertNotNull($reactivated);
        $this->assertSame('active', $reactivated->status);
        $this->assertSame((string) $original->created_by, (string) $reactivated->created_by);
        $this->assertSame((string) $original->created_at, (string) $reactivated->created_at);
    }

    #[Test]
    public function tenant_access_revocation_cannot_hide_a_person_with_linked_domain_records(): void
    {
        [$tenant, $actor] = $this->tenantPeopleManager();
        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];
        $created = $this->withHeaders($headers)->postJson('/api/people', [
            'first_name_en' => 'Protected Linked Person',
        ])->assertCreated();
        $person = Person::query()->where('public_id', $created->json('data.public_id'))->firstOrFail();

        $permission = Permission::query()->create([
            'name_en' => 'Manage Global Person Tenant Access',
            'slug' => 'people.tenant-access.manage',
            'category' => 'user',
            'scope' => 'global',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $actor->permissions()->attach($permission->getKey(), ['assigned_at' => now()]);

        DB::table('employees')->insert([
            'tenant_id' => $tenant->getKey(),
            'employee_number' => 'PEOPLE-REVOKE-GUARD-001',
            'user_id' => null,
            'person_id' => $person->getKey(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        try {
            app(\MaaleArna\MASES\Core\Domains\People\Services\PersonIdentityService::class)
                ->revokeTenantAccess(
                    $tenant->getKey(),
                    $person->getKey(),
                    $actor,
                    'Cannot revoke visibility while employee relationship remains',
                );
            $this->fail('Tenant access must not be revoked while a linked domain record exists.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Unlink tenant domain records', $exception->getMessage());
        }

        $this->assertDatabaseHas('person_tenant_accesses', [
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenant->getKey(),
            'employee_number' => 'PEOPLE-REVOKE-GUARD-001',
            'person_id' => $person->getKey(),
        ]);
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
