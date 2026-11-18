<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAggregateService;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAuthorizationService;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use Mockery;
use Tests\TestCase;

final class DocumentWorkflowAggregateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_submission_review_return_and_resume_are_persisted_as_atomic_audited_transitions(): void
    {
        [$tenantId, $legalEntityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.create');
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.submit');
        $authorization->shouldReceive('requireGovernedAction')->once()
            ->with($actor, $tenantId, 9, 4, 'documents.review', (int) $actor->getKey());
        $authorization->shouldReceive('requireGovernedAction')->once()
            ->with($actor, $tenantId, 9, 4, 'documents.return', (int) $actor->getKey());
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.update');
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft(
            $actor,
            $tenantId,
            $legalEntityId,
            $unitId,
            '01',
            'Board correspondence',
            9,
            4,
        );

        self::assertSame('draft', $workflow->status);
        self::assertSame(0, (int) $workflow->revision);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        self::assertSame('submitted', $workflow->status);
        self::assertSame(1, (int) $workflow->revision);
        self::assertSame($fileId, (int) $workflow->submitted_file_id);
        self::assertSame(hash('sha256', 'immutable source bytes'), $workflow->submitted_file_sha256);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
        self::assertSame('in_review', $workflow->status);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'return', 2, 'Correct the reference date.');
        self::assertSame('returned', $workflow->status);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'return_to_draft', 3);
        self::assertSame('draft', $workflow->status);
        self::assertSame(4, (int) $workflow->revision);
        self::assertSame(5, $workflow->events()->count());
    }

    public function test_stale_revision_and_cross_tenant_access_are_rejected(): void
    {
        [$tenantA, $entityA, $unitA] = $this->issuer();
        [$tenantB] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantA, 'documents.create');
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantA, $entityA, $unitA, '01', 'Tenant A record');

        try {
            $service->transition($actor, $tenantA, (int) $workflow->id, 'submit', 99);
            self::fail('A stale aggregate revision must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            self::assertSame('draft', $workflow->fresh()->status);
        }

        $this->expectException(ModelNotFoundException::class);
        $service->transition($actor, $tenantB, (int) $workflow->id, 'submit', 0);
    }

    public function test_workflow_event_rows_are_immutable_at_the_database_layer(): void
    {
        [$tenantId, $legalEntityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->once();
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $workflow = $this->app->make(DocumentWorkflowAggregateService::class)
            ->createDraft($actor, $tenantId, $legalEntityId, $unitId, '01', 'Immutable event test');
        $eventId = (int) $workflow->events()->value('id');

        try {
            DB::table('document_workflow_events')->where('id', $eventId)->update(['reason' => 'tampered']);
            self::fail('The database must reject workflow event updates.');
        } catch (QueryException) {
            self::assertDatabaseHas('document_workflow_events', ['id' => $eventId, 'reason' => null]);
        }

        $this->expectException(QueryException::class);
        DB::table('document_workflow_events')->where('id', $eventId)->delete();
    }

    public function test_rejection_is_audited_and_approval_can_advance_to_signing(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(4);
        $authorization->shouldReceive('requireGovernedAction')->times(5);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);

        $rejected = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Rejected lifecycle', 9, 4);
        $rejected = $service->transition($actor, $tenantId, (int) $rejected->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        $rejected = $service->transition($actor, $tenantId, (int) $rejected->id, 'start_review', 1);
        $rejected = $service->transition($actor, $tenantId, (int) $rejected->id, 'reject', 2, 'Insufficient supporting evidence.');
        self::assertSame('rejected', $rejected->status);
        self::assertSame('reject', $rejected->events()->latest('revision')->value('action'));

        $approved = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Approval lifecycle', 9, 4);
        $approved = $service->transition($actor, $tenantId, (int) $approved->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        $approved = $service->transition($actor, $tenantId, (int) $approved->id, 'start_review', 1);
        $approved = $service->transition($actor, $tenantId, (int) $approved->id, 'approve', 2);
        $approved = $service->transition($actor, $tenantId, (int) $approved->id, 'begin_signing', 3);
        self::assertSame('signing', $approved->status);
        self::assertSame(4, (int) $approved->revision);
    }

    public function test_issue_transition_fails_closed_without_a_matching_issued_record(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->twice();
        $authorization->shouldReceive('requireGovernedAction')->times(4);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Issue guard', 9, 4);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'approve', 2);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'begin_signing', 3);

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, ['issued_document_id' => 999999]);
            self::fail('Issuance must reject an absent issued document record.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            self::assertSame('signing', $workflow->fresh()->status);
            self::assertSame(4, (int) $workflow->fresh()->revision);
        }
    }

    public function test_revoke_and_supersede_update_issued_documents_and_append_lifecycle_events(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $sha = hash('sha256', 'immutable source bytes');
        $revocable = $this->issuedDocument($tenantId, $entityId, $unitId, $fileId, (int) $actor->getKey(), $sha, 1);
        $original = $this->issuedDocument($tenantId, $entityId, $unitId, $fileId, (int) $actor->getKey(), $sha, 2);
        $replacement = $this->issuedDocument($tenantId, $entityId, $unitId, $fileId, (int) $actor->getKey(), $sha, 3, (int) $original);

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(4);
        $authorization->shouldReceive('requireGovernedAction')->times(10);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);

        $revokeFlow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Revoke test');
        $revokeFlow = $service->transition($actor, $tenantId, (int) $revokeFlow->id, 'issue', 4, null, [
            'issued_document_id' => $revocable,
        ]);
        $revokeFlow = $service->transition($actor, $tenantId, (int) $revokeFlow->id, 'revoke', 5, 'Authority withdrawn');
        self::assertSame('revoked', $revokeFlow->status);
        self::assertSame('revoked', DB::table('documents')->where('id', $revocable)->value('status'));
        self::assertDatabaseHas('document_lifecycle_events', [
            'document_id' => $revocable,
            'event_type' => 'revoked',
            'reason' => 'Authority withdrawn',
        ]);

        $supersedeFlow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Supersede test');
        $supersedeFlow = $service->transition($actor, $tenantId, (int) $supersedeFlow->id, 'issue', 4, null, [
            'issued_document_id' => $original,
        ]);
        $supersedeFlow = $service->transition($actor, $tenantId, (int) $supersedeFlow->id, 'supersede', 5, 'Replacement formally issued', [
            'replacement_document_id' => $replacement,
        ]);
        self::assertSame('superseded', $supersedeFlow->status);
        self::assertSame('superseded', DB::table('documents')->where('id', $original)->value('status'));
        self::assertDatabaseHas('document_lifecycle_events', [
            'document_id' => $original,
            'event_type' => 'superseded',
            'reason' => 'Replacement formally issued',
        ]);
    }

    public function test_issue_rejects_file_binary_drift_after_submission(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->twice();
        $authorization->shouldReceive('requireGovernedAction')->times(4);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Binary drift');
        Storage::disk('local')->put('documents/source.pdf', 'changed bytes');

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, ['issued_document_id' => 999999]);
            self::fail('Issuance must reject a changed submitted binary.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            self::assertSame('signing', $workflow->fresh()->status);
            self::assertSame(4, (int) $workflow->fresh()->revision);
        }
    }

    public function test_submitted_file_is_bound_to_server_computed_sha256_and_tenant_fk_rejects_cross_tenant_linkage(): void
    {
        [$tenantA, $entityA, $unitA] = $this->issuer();
        [$tenantB] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileA = $this->file($tenantA, (int) $actor->getKey());
        $fileB = $this->file($tenantB, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->twice();
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantA, $entityA, $unitA, '01', 'File integrity');
        $workflow = $service->transition($actor, $tenantA, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileA,
            'submitted_file_version' => 1,
        ]);

        self::assertSame(hash('sha256', 'immutable source bytes'), $workflow->submitted_file_sha256);

        $this->expectException(QueryException::class);
        DB::table('document_workflow_aggregates')->where('id', $workflow->id)
            ->update(['submitted_file_id' => $fileB, 'submitted_file_sha256' => hash('sha256', 'other tenant')]);
    }

    public function test_failed_event_append_rolls_back_aggregate_state_and_revision(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->twice();
        $authorization->shouldReceive('requireGovernedAction')->once();
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Rollback on event failure', 9, 4);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);

        // Occupy the next revision with a valid event so the attempted
        // transition's append fails on the database unique constraint.
        DB::table('document_workflow_events')->insert([
            'workflow_id' => $workflow->id,
            'tenant_id' => $tenantId,
            'revision' => 2,
            'action' => 'reserved_revision',
            'from_status' => 'submitted',
            'to_status' => 'in_review',
            'actor_user_id' => $actor->getKey(),
            'reason' => 'Test-only conflicting revision',
            'metadata' => null,
            'occurred_at' => now(),
        ]);

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
            self::fail('The duplicate event revision must abort the transition.');
        } catch (QueryException) {
            $fresh = $workflow->fresh();
            self::assertSame('submitted', $fresh->status);
            self::assertSame(1, (int) $fresh->revision);
            self::assertSame(3, $fresh->events()->count());
        }
    }

    public function test_aggregate_uses_the_concrete_rbac_and_governance_authorization_adapter(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenantId]);
        $actor->tenants()->attach($tenantId, ['joined_at' => now()]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        foreach (['documents.create', 'documents.submit', 'documents.review'] as $slug) {
            $permission = Permission::query()->create([
                'slug' => $slug,
                'name_am' => $slug,
                'name_en' => $slug,
                'description_am' => $slug,
                'description_en' => $slug,
                'category' => 'documents',
                'scope' => 'tenant',
                'tenant_id' => null,
                'application_id' => null,
                'is_active' => true,
                'is_system' => false,
            ]);
            $actor->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        $authorization = $this->app->make(DocumentWorkflowAuthorizationService::class);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $this->app->make(AuthorizationService::class)->clearUserCache($actor);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Concrete adapter', 9, 4);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);

        // Tenant RBAC alone must not authorize a governed review without a
        // corresponding active position/action grant.
        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
            self::fail('A tenant permission alone must not authorize governed review.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('submitted', $workflow->fresh()->status);
            self::assertSame(1, (int) $workflow->fresh()->revision);
        }

        self::assertInstanceOf(DocumentWorkflowAuthorizationService::class, $this->app->make(DocumentWorkflowAuthorizer::class));
    }

    private function workflowReadyToIssue(
        DocumentWorkflowAggregateService $service,
        User $actor,
        int $tenantId,
        int $entityId,
        int $unitId,
        int $fileId,
        string $title,
    ): \MaaleArna\MASES\Core\Domains\Documents\Models\DocumentWorkflowAggregate {
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', $title, 9, 4);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'approve', 2);
        return $service->transition($actor, $tenantId, (int) $workflow->id, 'begin_signing', 3);
    }

    private function issuedDocument(
        int $tenantId,
        int $entityId,
        int $unitId,
        int $fileId,
        int $actorId,
        string $sha256,
        int $sequence,
        ?int $replacesDocumentId = null,
    ): int {
        if (! DB::table('document_entity_code_assignments')->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId)->exists()) {
            DB::table('document_entity_code_assignments')->insert([
                'tenant_id' => $tenantId, 'legal_entity_id' => $entityId, 'entity_code' => '01',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (! DB::table('document_department_code_assignments')->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId)->where('organization_unit_id', $unitId)->exists()) {
            DB::table('document_department_code_assignments')->insert([
                'tenant_id' => $tenantId, 'legal_entity_id' => $entityId, 'organization_unit_id' => $unitId,
                'entity_code' => '01', 'department_code' => '01', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (int) DB::table('documents')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'organization_unit_id' => $unitId,
            'entity_code' => '01',
            'department_code' => '01',
            'document_type_code' => 'LET',
            'document_type_number' => '01',
            'ec_year' => 2019,
            'sequence_number' => $sequence,
            'canonical_number' => sprintf('ማዓ/01/01/01/2019/%04d', $sequence),
            'replaces_document_id' => $replacesDocumentId,
            'title' => 'Issued test document '.$sequence,
            'file_id' => $fileId,
            'file_version' => 1,
            'file_sha256' => $sha256,
            'status' => 'issued',
            'request_hash' => hash('sha256', 'request-'.$sequence.'-'.uniqid()),
            'idempotency_key' => 'workflow-test-'.$tenantId.'-'.$sequence.'-'.uniqid(),
            'metadata' => null,
            'issued_by' => $actorId,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{int,int,int} */
    private function issuer(): array
    {
        $tenantId = (int) Tenant::factory()->create(['is_active' => true])->getKey();
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenantId,
            'code' => 'DOC-'.uniqid(),
            'name_en' => 'Workflow Test Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'code' => 'UNIT-'.uniqid(),
            'unit_type' => 'department',
            'name_en' => 'Records',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $entityId, $unitId];
    }

    private function file(int $tenantId, int $userId): int
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/source.pdf', 'immutable source bytes');

        return (int) DB::table('files')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'original_name' => 'source.pdf',
            'name' => 'source.pdf',
            'size' => strlen('immutable source bytes'),
            'path' => 'documents/source.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
