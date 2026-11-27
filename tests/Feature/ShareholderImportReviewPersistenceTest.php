<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareholderImportDecision;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderImportReviewService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShareholderImportReviewPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function submission_review_and_approval_are_tenant_scoped_and_append_audited_decisions(): void
    {
        $tenant = Tenant::factory()->create();
        $submitter = $this->tenantActor($tenant, 'shareholder.import.submit');
        $reviewer = $this->tenantActor($tenant, 'shareholder.import.review');
        $approver = $this->tenantActor($tenant, 'shareholder.import.approve');

        $service = app(ShareholderImportReviewService::class);
        $submission = $service->submit($this->validPreview(), $submitter, (int) $tenant->id);

        self::assertSame('submitted_for_review', $submission->status);
        self::assertSame(1, $submission->version);
        self::assertSame(1, $submission->decisions->count());
        self::assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'shareholder.import.submitted',
            'resource_id' => (string) $submission->id,
        ]);

        $reviewed = $service->review(
            (int) $submission->id,
            $reviewer,
            (int) $tenant->id,
            1,
            2,
            'accept',
            'Both source rows were independently checked.',
            [
                'SRC-001' => ['RECORD-001'],
                'SRC-002' => ['RECORD-002'],
            ],
        );

        self::assertSame('review_accepted', $reviewed->status);
        self::assertSame(2, $reviewed->version);
        self::assertSame(2, $reviewed->decisions->count());

        $approved = $service->approve(
            (int) $submission->id,
            $approver,
            (int) $tenant->id,
            2,
            'GA-RESOLUTION-REF-001',
            'Approved under the cited authority instrument.',
        );

        self::assertSame('approved_for_import', $approved->status);
        self::assertSame(3, $approved->version);
        self::assertSame(3, $approved->decisions->count());
        self::assertSame([1, 2, 3], $approved->decisions->pluck('sequence')->all());
        self::assertSame(
            [$submitter->id, $reviewer->id, $approver->id],
            $approved->decisions->pluck('actor_user_id')->all(),
        );
        self::assertDatabaseCount('audit_logs', 3);
        self::assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'shareholder.import.approved_for_import',
            'resource_id' => (string) $submission->id,
        ]);

        // Approval is a governance state only; no shareholder row is created.
        self::assertDatabaseCount('shareholders', 0);
    }

    #[Test]
    public function a_stale_expected_version_cannot_overwrite_a_newer_decision(): void
    {
        $tenant = Tenant::factory()->create();
        $submitter = $this->tenantActor($tenant, 'shareholder.import.submit');
        $reviewer = $this->tenantActor($tenant, 'shareholder.import.review');
        $approver = $this->tenantActor($tenant, 'shareholder.import.approve');
        $service = app(ShareholderImportReviewService::class);

        $submission = $service->submit($this->validPreview(), $submitter, (int) $tenant->id);
        $service->review(
            (int) $submission->id,
            $reviewer,
            (int) $tenant->id,
            1,
            2,
            'accept',
            'Evidence checked.',
            ['SRC-001' => ['E-1'], 'SRC-002' => ['E-2']],
        );

        try {
            $service->approve(
                (int) $submission->id,
                $approver,
                (int) $tenant->id,
                1,
                'AUTH-001',
                'Stale approval attempt.',
            );
            self::fail('A stale expected version must be rejected.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('changed', $exception->getMessage());
        }

        self::assertDatabaseHas('shareholder_import_submissions', [
            'id' => $submission->id,
            'status' => 'review_accepted',
            'version' => 2,
        ]);
        self::assertDatabaseCount('shareholder_import_decisions', 2);
        self::assertDatabaseCount('audit_logs', 2);
    }

    #[Test]
    public function inactive_or_missing_tenant_membership_blocks_submission(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantPermission($actor, 'shareholder.import.submit');

        try {
            app(ShareholderImportReviewService::class)->submit(
                $this->validPreview(),
                $actor,
                (int) $tenant->id,
            );
            self::fail('A user without active tenant membership must be denied.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertDatabaseCount('shareholder_import_submissions', 0);
        }
    }

    #[Test]
    public function decision_records_reject_eloquent_update_and_delete_attempts(): void
    {
        $tenant = Tenant::factory()->create();
        $submitter = $this->tenantActor($tenant, 'shareholder.import.submit');
        $submission = app(ShareholderImportReviewService::class)->submit(
            $this->validPreview(),
            $submitter,
            (int) $tenant->id,
        );
        $decision = $submission->decisions->firstOrFail();

        $this->expectException(\LogicException::class);
        $decision->note = 'tampered';
        $decision->save();
    }

    #[Test]
    public function failed_mandatory_audit_insert_rolls_back_submission_and_decision(): void
    {
        $tenant = Tenant::factory()->create();
        $submitter = $this->tenantActor($tenant, 'shareholder.import.submit');
        $failAudit = true;

        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$failAudit): void {
            if ($failAudit && str_starts_with(strtolower(ltrim($query->sql)), 'insert into "audit_logs"')) {
                throw new \RuntimeException('Synthetic mandatory audit persistence failure.');
            }
        });

        try {
            app(ShareholderImportReviewService::class)->submit(
                $this->validPreview(),
                $submitter,
                (int) $tenant->id,
            );
            self::fail('Submission must fail when its mandatory audit write fails.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('mandatory audit persistence failure', $exception->getMessage());
        } finally {
            $failAudit = false;
        }

        self::assertDatabaseCount('shareholder_import_submissions', 0);
        self::assertDatabaseCount('shareholder_import_decisions', 0);
        self::assertDatabaseCount('audit_logs', 0);
    }

    #[Test]
    public function submitted_payload_and_tenant_provenance_cannot_be_changed(): void
    {
        $tenant = Tenant::factory()->create();
        $submitter = $this->tenantActor($tenant, 'shareholder.import.submit');
        $submission = app(ShareholderImportReviewService::class)->submit(
            $this->validPreview(),
            $submitter,
            (int) $tenant->id,
        );

        $this->expectException(\\LogicException::class);
        $submission->payload = ['rows' => []];
        $submission->save();
    }

    private function tenantActor(Tenant $tenant, string $permissionSlug): User
    {
        $actor = User::factory()->create(['is_active' => true]);

        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'status' => 'active',
            'joined_at' => now(),
            'settings' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->grantPermission($actor, $permissionSlug);

        return $actor;
    }

    private function grantPermission(User $actor, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name_am' => $slug,
                'name_en' => $slug,
                'description_am' => $slug,
                'description_en' => $slug,
                'category' => 'shareholder_import',
                'scope' => 'tenant',
                'tenant_id' => null,
                'application_id' => null,
                'is_active' => true,
                'is_system' => false,
            ],
        );

        $actor->permissions()->attach($permission->id, [
            'assigned_at' => now(),
            'assigned_by' => $actor->id,
        ]);
    }

    /** @return array<string,mixed> */
    private function validPreview(): array
    {
        return [
            'mode' => 'preview_only',
            'format' => 'csv',
            'writes_performed' => false,
            'source_file_stored' => false,
            'summary' => ['total' => 2, 'valid' => 2, 'invalid' => 0],
            'rows' => [
                [
                    'valid' => true,
                    'source_reference' => 'SRC-001',
                    'shareholder_type' => 'individual',
                    'first_name_en' => 'Synthetic',
                    'father_name_en' => 'Person',
                ],
                [
                    'valid' => true,
                    'source_reference' => 'SRC-002',
                    'shareholder_type' => 'legal_entity',
                    'entity_name_en' => 'Synthetic Entity',
                ],
            ],
        ];
    }
}
