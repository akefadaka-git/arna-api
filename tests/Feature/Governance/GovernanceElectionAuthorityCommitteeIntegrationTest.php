<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeAppointmentService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeSecurityService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Tests\TestCase;

final class GovernanceElectionAuthorityCommitteeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_authority_evidence_requires_separate_review_and_digest_revalidation_before_verification(): void
    {
        $tenant = $this->tenant();
        app(TenantContextService::class)->setTenantById((int) $tenant->id);

        $creator = $this->user($tenant, 'election-creator');
        $this->grant($creator, 'governance.election.manage');
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $creator, [
            'code' => 'AUTH-EVIDENCE-' . $tenant->id,
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);

        $recorder = $this->user($tenant, 'evidence-recorder');
        foreach (['governance.election.manage', 'file.view', 'file.download'] as $slug) {
            $this->grant($recorder, $slug);
        }
        $reviewer = $this->user($tenant, 'evidence-reviewer');
        $this->grant($reviewer, 'governance.election.authority.review');
        $verifier = $this->user($tenant, 'authority-verifier');
        foreach (['governance.election.authority.verify', 'file.view', 'file.download'] as $slug) {
            $this->grant($verifier, $slug);
        }

        $bytes = "%PDF-1.4\nAuthoritative GA resolution\n";
        $document = $this->publishedFile($tenant, $recorder, $bytes);
        $service = app(GovernanceElectionAuthorityService::class);
        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->id,
            (int) $election->id,
            'general_assembly_resolution',
            'GA-RESOLUTION-AUTHORITY-TEST',
            '2026-11-20',
            (int) $document->id,
            $recorder,
        );

        $this->assertSame('pending_review', $evidence->status);
        $this->assertSame(hash('sha256', $bytes), $evidence->content_sha256);
        $this->assertSame((int) $document->id, (int) $evidence->document_id);

        try {
            $service->reviewAuthorityEvidence(
                (int) $tenant->id, (int) $election->id, (int) $evidence->id,
                'reviewed', 'The recorder cannot review their own evidence.', $recorder,
            );
            $this->fail('The evidence recorder must not review their own authority evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewer', $exception->errors());
        }

        $reviewed = $service->reviewAuthorityEvidence(
            (int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'reviewed', 'The instrument and published source document were reviewed.', $reviewer,
        );
        $this->assertSame('reviewed', $reviewed->status);
        $this->assertSame('draft', $election->fresh()->status);
        $this->assertNull($election->fresh()->authority_verified_at);

        Storage::disk('local')->put($document->path, 'changed bytes after evidence recording');
        try {
            $service->verifyAuthority(
                (int) $tenant->id, (int) $election->id, (int) $evidence->id,
                'The stored authority bytes must be revalidated before verification.', $verifier,
            );
            $this->fail('A changed authority document must not be verified.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_id', $exception->errors());
        }
        $this->assertSame('reviewed', $evidence->fresh()->status);
        $this->assertNull($election->fresh()->authority_verified_at);

        Storage::disk('local')->put($document->path, $bytes);
        $verified = $service->verifyAuthority(
            (int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'The verified source is authoritative for this election.', $verifier,
        );

        $this->assertSame((int) $evidence->id, (int) $verified->authority_evidence_id);
        $this->assertSame((int) $verifier->id, (int) $verified->authority_verified_by);
        $this->assertNotNull($verified->authority_verified_at);
        $this->assertSame('verified', $evidence->fresh()->status);
    }

    public function test_committee_appointment_and_revocation_require_verified_authority_and_write_atomic_security_events(): void
    {
        $tenant = $this->tenant();
        app(TenantContextService::class)->setTenantById((int) $tenant->id);

        $creator = $this->user($tenant, 'committee-election-creator');
        $this->grant($creator, 'governance.election.manage');
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $creator, [
            'code' => 'COMMITTEE-AUTH-' . $tenant->id,
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);

        $recorder = $this->user($tenant, 'committee-evidence-recorder');
        foreach (['governance.election.manage', 'file.view', 'file.download'] as $slug) {
            $this->grant($recorder, $slug);
        }
        $reviewer = $this->user($tenant, 'committee-evidence-reviewer');
        $this->grant($reviewer, 'governance.election.authority.review');
        $verifier = $this->user($tenant, 'committee-authority-verifier');
        foreach (['governance.election.authority.verify', 'file.view', 'file.download'] as $slug) {
            $this->grant($verifier, $slug);
        }

        $bytes = "%PDF-1.4\nCommittee authority resolution\n";
        $document = $this->publishedFile($tenant, $recorder, $bytes);
        $authority = app(GovernanceElectionAuthorityService::class);
        $evidence = $authority->recordAuthorityEvidence(
            (int) $tenant->id, (int) $election->id,
            'general_assembly_resolution', 'GA-COMMITTEE-AUTHORITY',
            null, (int) $document->id, $recorder,
        );

        $appointer = $this->user($tenant, 'committee-appointer');
        foreach (['governance.election.committee.appoint', 'governance.election.committee.revoke', 'governance.election.committee.view'] as $slug) {
            $this->grant($appointer, $slug);
        }
        $memberUser = $this->user($tenant, 'committee-member');
        $person = new Person(['first_name_en' => 'Committee', 'father_name_en' => 'Member']);
        $person->user_id = $memberUser->id;
        $person->status = 'active';
        $person->save();
        PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $tenant->id,
            'created_by' => $memberUser->id,
            'status' => 'active',
        ]);

        $appointments = app(GovernanceElectionCommitteeAppointmentService::class);
        try {
            $appointments->appoint(
                (int) $tenant->id, (int) $election->id, (int) $person->id, (int) $memberUser->id,
                (int) $evidence->id, 'GA-COMMITTEE-APPOINTMENT', 'committee_member', false,
                now()->toDateTimeString(), $appointer,
            );
            $this->fail('A committee appointment must not proceed before authority verification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('election', $exception->errors());
        }
        $this->assertSame(0, DB::table('governance_election_committee_members')->where('election_id', $election->id)->count());

        $authority->reviewAuthorityEvidence(
            (int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'reviewed', 'The committee authority instrument was reviewed.', $reviewer,
        );
        $authority->verifyAuthority(
            (int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'The authority instrument was verified for committee appointment.', $verifier,
        );

        $appointed = $appointments->appoint(
            (int) $tenant->id, (int) $election->id, (int) $person->id, (int) $memberUser->id,
            (int) $evidence->id, 'GA-COMMITTEE-APPOINTMENT', 'committee_member', false,
            now()->toDateTimeString(), $appointer,
        );
        $this->assertSame('active', $appointed->status);
        $this->assertSame((int) $evidence->id, (int) $appointed->appointment_evidence_id);

        $revoked = $appointments->revoke(
            (int) $tenant->id, (int) $election->id, (int) $appointed->id,
            'Appointment concluded by the appointing authority.', $appointer,
        );
        $this->assertSame('revoked', $revoked->status);
        $this->assertNotNull($revoked->revoked_at);

        $security = app(GovernanceElectionCommitteeSecurityService::class);
        $this->assertSame([
            'valid' => true,
            'verified_events' => 2,
            'first_invalid_sequence' => null,
        ], $security->verifySecurityEventChain((int) $tenant->id, (int) $election->id));

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'resource_type' => 'governance_election_committee_member',
            'resource_id' => (string) $appointed->id,
            'action' => 'governance.election.committee.member.appointed',
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name_am' => 'የምርጫ ሙከራ',
            'name_en' => 'Election Authority Integration Test',
            'slug' => 'election-authority-' . bin2hex(random_bytes(4)),
            'domain' => 'election-authority-' . bin2hex(random_bytes(4)) . '.example.test',
            'is_active' => true,
        ]);
    }

    private function user(Tenant $tenant, string $label): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Election',
            'father_name_en' => ucfirst($label),
            'email' => $label . '-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now()]);

        return $user;
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(['slug' => $slug], [
            'name_am' => $slug,
            'name_en' => $slug,
            'description_am' => $slug,
            'description_en' => $slug,
            'category' => 'governance',
            'scope' => 'tenant',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => true,
        ]);
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
    }

    private function publishedFile(Tenant $tenant, User $owner, string $bytes): File
    {
        $path = 'tenant_' . $tenant->id . '/authority-' . bin2hex(random_bytes(4)) . '.pdf';
        Storage::disk('local')->put($path, $bytes);

        return File::query()->create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'original_name' => 'authority.pdf',
            'name' => basename($path),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($bytes),
            'path' => $path,
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);
    }
}
