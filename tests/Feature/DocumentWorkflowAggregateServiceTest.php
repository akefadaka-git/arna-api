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
use MaaleArna\MASES\Core\Domains\Documents\Services\SodiumDocumentSignatureEvidenceVerifier;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use Mockery;
use Tests\TestCase;

final class DocumentWorkflowAggregateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_public_file_is_rejected_as_formal_evidence_before_snapshot_capture(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey(), true);
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.create');
        $authorization->shouldReceive('requirePermission')->twice()->with($actor, $tenantId, 'documents.submit');
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Public evidence must fail closed');

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
                'submitted_file_id' => $fileId,
                'submitted_file_version' => 1,
            ]);
            self::fail('A publicly accessible File Management object must not be accepted as formal evidence.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            self::assertSame('draft', $workflow->fresh()->status);
            self::assertSame(0, DB::table('document_file_versions')->where('tenant_id', $tenantId)->count());
            self::assertSame([], Storage::disk('local')->allFiles('document-evidence'));
        }
    }

    public function test_draft_submission_review_return_and_resume_are_persisted_as_atomic_audited_transitions(): void
    {
        [$tenantId, $legalEntityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.create');
        $authorization->shouldReceive('requirePermission')->twice()->with($actor, $tenantId, 'documents.submit');
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
        $authorization->shouldReceive('requirePermission')->times(9);
        $authorization->shouldReceive('requireGovernedAction')->times(6);
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

        $withdrawn = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Withdrawal lifecycle', 9, 4);
        $withdrawn = $service->transition($actor, $tenantId, (int) $withdrawn->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        $withdrawn = $service->transition($actor, $tenantId, (int) $withdrawn->id, 'withdraw', 1, 'Request withdrawn by originator.');
        self::assertSame('withdrawn', $withdrawn->status);
        self::assertSame('withdraw', $withdrawn->events()->latest('revision')->value('action'));
    }

    public function test_issue_creates_canonical_document_and_signature_evidence_atomically(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
        $authorization->shouldReceive('requireGovernedAction')->times(4);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Atomic issue');
        $signature = $this->signatureEvidence($actor, $tenantId, $workflow);

        $issued = $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, [
            'signature_evidence' => $signature,
        ]);

        self::assertSame('issued', $issued->status);
        self::assertSame(5, (int) $issued->revision);
        $document = DB::table('documents')->where('id', $issued->issued_document_id)->first();
        self::assertNotNull($document);
        self::assertSame('issued', $document->status);
        self::assertSame('ማዓ/01/01/01/'.$document->ec_year.'/'.str_pad((string) $document->sequence_number, 4, '0', STR_PAD_LEFT), $document->canonical_number);
        self::assertSame(hash('sha256', 'immutable source bytes'), $document->file_sha256);
        self::assertNotNull($document->immutable_file_version_id);
        self::assertNotNull($document->signature_evidence_id);
        self::assertDatabaseHas('document_signature_evidence', [
            'id' => $document->signature_evidence_id,
            'tenant_id' => $tenantId,
            'workflow_id' => $workflow->id,
            'signer_user_id' => $actor->getKey(),
            'algorithm' => 'Ed25519',
        ]);
        self::assertDatabaseHas('document_lifecycle_events', [
            'document_id' => $document->id,
            'event_type' => 'issued',
        ]);
    }

    public function test_invalid_or_untrusted_signature_fails_before_number_allocation_or_document_creation(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
        $authorization->shouldReceive('requireGovernedAction')->times(4);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Invalid signature');
        $signature = $this->signatureEvidence($actor, $tenantId, $workflow);
        $signature['signature'] = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, [
                'signature_evidence' => $signature,
            ]);
            self::fail('An invalid Ed25519 signature must never issue a document.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('signing', $workflow->fresh()->status);
            self::assertSame(0, DB::table('documents')->where('tenant_id', $tenantId)->count());
            self::assertSame(0, DB::table('document_number_counters')->where('tenant_id', $tenantId)->count());
            self::assertSame(0, DB::table('document_signature_evidence')->where('tenant_id', $tenantId)->count());
        }
    }

    public function test_revoked_trusted_signing_key_fails_closed_before_number_allocation(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
        $authorization->shouldReceive('requireGovernedAction')->times(5);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Revoked key');
        $signature = $this->signatureEvidence($actor, $tenantId, $workflow);

        app(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentSigningKeyRevocationService::class)
            ->revoke($actor, $tenantId, 9, 4, $signature['key_id'], 'Signing key custody was compromised.');

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, [
                'signature_evidence' => $signature,
            ]);
            self::fail('A revoked signing key must not authorize document issuance.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('signing', $workflow->fresh()->status);
            self::assertSame(0, DB::table('documents')->where('tenant_id', $tenantId)->count());
            self::assertSame(0, DB::table('document_number_counters')->where('tenant_id', $tenantId)->count());
            self::assertDatabaseHas('document_signing_key_revocations', [
                'tenant_id' => $tenantId,
                'reason' => 'Signing key custody was compromised.',
            ]);
        }
    }

    public function test_issue_failure_rolls_back_document_signature_counter_lifecycle_and_workflow_event_together(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
        $authorization->shouldReceive('requireGovernedAction')->times(4);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Atomic rollback issue');
        $signature = $this->signatureEvidence($actor, $tenantId, $workflow);

        // Force the final workflow-event append to violate the unique revision
        // constraint after issuance has created its number, signature and row.
        DB::table('document_workflow_events')->insert([
            'workflow_id' => $workflow->id,
            'tenant_id' => $tenantId,
            'revision' => 5,
            'action' => 'reserved_issue_revision',
            'from_status' => 'signing',
            'to_status' => 'issued',
            'actor_user_id' => $actor->getKey(),
            'reason' => 'Test-only reserved revision',
            'metadata' => null,
            'occurred_at' => now(),
        ]);

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, [
                'signature_evidence' => $signature,
            ]);
            self::fail('The duplicate workflow revision must abort the entire issuance transaction.');
        } catch (QueryException) {
            self::assertSame('signing', $workflow->fresh()->status);
            self::assertSame(4, (int) $workflow->fresh()->revision);
            self::assertSame(0, DB::table('documents')->where('tenant_id', $tenantId)->count());
            self::assertSame(0, DB::table('document_signature_evidence')->where('tenant_id', $tenantId)->count());
            self::assertSame(0, DB::table('document_lifecycle_events')->where('tenant_id', $tenantId)->count());
            self::assertSame(0, DB::table('document_number_counters')->where('tenant_id', $tenantId)->count());
        }
    }

    public function test_document_binary_version_and_signing_key_records_are_append_only_in_mysql_and_sqlite(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Append-only binary');
        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);

        try {
            DB::table('document_file_versions')->where('id', $workflow->submitted_file_evidence_id)
                ->update(['sha256' => str_repeat('0', 64)]);
            self::fail('A sealed document file version cannot be modified.');
        } catch (QueryException) {
            self::assertDatabaseHas('document_file_versions', [
                'id' => $workflow->submitted_file_evidence_id,
                'sha256' => hash('sha256', 'immutable source bytes'),
            ]);
        }

        $this->expectException(QueryException::class);
        DB::table('document_file_versions')->where('id', $workflow->submitted_file_evidence_id)->delete();
    }

    public function test_revoke_and_supersede_update_issued_documents_and_append_lifecycle_events(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(9);
        $authorization->shouldReceive('requireGovernedAction')->times(14);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);

        $revokeFlow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Revoke test');
        $revokeFlow = $service->transition($actor, $tenantId, (int) $revokeFlow->id, 'issue', 4, null, [
            'signature_evidence' => $this->signatureEvidence($actor, $tenantId, $revokeFlow),
        ]);
        $revocable = (int) $revokeFlow->issued_document_id;
        $revokeFlow = $service->transition($actor, $tenantId, (int) $revokeFlow->id, 'revoke', 5, 'Authority withdrawn');
        self::assertSame('revoked', $revokeFlow->status);
        self::assertSame('revoked', DB::table('documents')->where('id', $revocable)->value('status'));
        self::assertDatabaseHas('document_lifecycle_events', [
            'document_id' => $revocable,
            'event_type' => 'revoked',
            'reason' => 'Authority withdrawn',
        ]);

        $originalFlow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Original for supersession');
        $originalFlow = $service->transition($actor, $tenantId, (int) $originalFlow->id, 'issue', 4, null, [
            'signature_evidence' => $this->signatureEvidence($actor, $tenantId, $originalFlow),
        ]);
        $original = (int) $originalFlow->issued_document_id;

        $replacementFlow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Replacement document');
        $replacementFlow = $service->transition($actor, $tenantId, (int) $replacementFlow->id, 'issue', 4, null, [
            'signature_evidence' => $this->signatureEvidence($actor, $tenantId, $replacementFlow),
            'replaces_document_id' => $original,
        ]);
        $replacement = (int) $replacementFlow->issued_document_id;
        self::assertSame($original, (int) DB::table('documents')->where('id', $replacement)->value('replaces_document_id'));

        $originalFlow = $service->transition($actor, $tenantId, (int) $originalFlow->id, 'supersede', 5, 'Replacement formally issued', [
            'replacement_document_id' => $replacement,
        ]);
        self::assertSame('superseded', $originalFlow->status);
        self::assertSame('superseded', DB::table('documents')->where('id', $original)->value('status'));
        self::assertDatabaseHas('document_lifecycle_events', [
            'document_id' => $original,
            'event_type' => 'superseded',
            'reason' => 'Replacement formally issued',
        ]);
    }

    public function test_immutable_snapshot_preserves_submitted_bytes_when_file_management_source_changes(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
        $authorization->shouldReceive('requireGovernedAction')->times(4);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $this->workflowReadyToIssue($service, $actor, $tenantId, $entityId, $unitId, $fileId, 'Immutable evidence');
        Storage::disk('local')->put('documents/source.pdf', 'changed source bytes');

        $issued = $service->transition($actor, $tenantId, (int) $workflow->id, 'issue', 4, null, [
            'signature_evidence' => $this->signatureEvidence($actor, $tenantId, $workflow),
        ]);
        $document = DB::table('documents')->where('id', $issued->issued_document_id)->first();
        self::assertSame('issued', $issued->status);
        self::assertSame(hash('sha256', 'immutable source bytes'), $document->file_sha256);
        self::assertNotSame(hash('sha256', 'changed source bytes'), $document->file_sha256);
    }

    public function test_submitted_file_is_bound_to_server_computed_sha256_and_tenant_fk_rejects_cross_tenant_linkage(): void
    {
        [$tenantA, $entityA, $unitA] = $this->issuer();
        [$tenantB] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileA = $this->file($tenantA, (int) $actor->getKey());
        $fileB = $this->file($tenantB, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->times(3);
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
        $authorization->shouldReceive('requirePermission')->times(3);
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
        $this->app->make(\MaaleArna\MASES\Core\Tenancy\Services\TenantContextService::class)->setTenantById($tenantId);
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenantId]);
        $this->actingAs($actor);
        \Laravel\Sanctum\Sanctum::actingAs($actor, ['*']);
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

        $bodyId = (int) DB::table('governance_bodies')->insertGetId([
            'tenant_id' => $tenantId, 'code' => 'DOC-BODY-'.uniqid(), 'body_type' => 'board',
            'name_am' => 'የሰነድ ቦርድ', 'name_en' => 'Document Board', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $positionId = (int) DB::table('governance_positions')->insertGetId([
            'tenant_id' => $tenantId, 'governance_body_id' => $bodyId, 'code' => 'DOC-POS-'.uniqid(),
            'name_am' => 'አባል', 'name_en' => 'Document Reviewer', 'is_voting' => true,
            'is_officer' => false, 'sort_order' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $authorization = $this->app->make(DocumentWorkflowAuthorizationService::class);
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);
        $this->app->make(AuthorizationService::class)->clearUserCache($actor);
        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft(
            $actor, $tenantId, $entityId, $unitId, '01', 'Concrete adapter', $bodyId, $positionId
        );
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

        $person = \MaaleArna\MASES\Core\Domains\People\Models\Person::query()->create([
            'first_name_en' => 'Governance signatory',
        ]);
        DB::table('people')->where('id', $person->getKey())->update(['user_id' => $actor->getKey()]);
        DB::table('person_tenant_accesses')->insert([
            'person_id' => $person->getKey(), 'tenant_id' => $tenantId,
            'created_by' => $actor->getKey(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $grantor = \MaaleArna\MASES\Core\Domains\People\Models\Person::query()->create([
            'first_name_en' => 'Independent grantor',
        ]);
        DB::table('person_tenant_accesses')->insert([
            'person_id' => $grantor->getKey(), 'tenant_id' => $tenantId,
            'created_by' => $actor->getKey(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('governance_memberships')->insert([
            'tenant_id' => $tenantId, 'governance_body_id' => $bodyId, 'governance_position_id' => $positionId,
            'user_id' => $actor->getKey(), 'member_name_en' => 'Governance signatory',
            'start_date' => now()->toDateString(), 'end_date' => null, 'status' => 'active',
            'is_ex_officio' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('governance_authority_grants')->insert([
            'tenant_id' => $tenantId, 'governance_body_id' => $bodyId, 'governance_position_id' => $positionId,
            'grantee_person_id' => $person->getKey(), 'grantor_person_id' => $grantor->getKey(),
            'created_by_user_id' => $actor->getKey(), 'action' => 'documents.review',
            'effective_from' => now()->toDateString(), 'effective_until' => null,
            'reason' => 'Owner-approved integration test authority',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        self::assertSame($tenantId, (int) \mases_tenant_id());
        self::assertTrue($authorization->hasPermission($actor, $tenantId, 'documents.review'));
        self::assertTrue(app(\MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService::class)
            ->allows($actor, $tenantId, $bodyId, $positionId, 'documents.review'));

        $approved = $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
        self::assertSame('in_review', $approved->status);
        self::assertSame(2, (int) $approved->revision);
        self::assertInstanceOf(DocumentWorkflowAuthorizationService::class, $this->app->make(DocumentWorkflowAuthorizer::class));
    }

    public function test_submit_database_rollback_preserves_durable_captured_intent_and_binary(): void
    {
        [$tenantId, $entityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizer::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.create');
        $authorization->shouldReceive('requirePermission')->twice()->with($actor, $tenantId, 'documents.submit');
        $this->app->instance(DocumentWorkflowAuthorizer::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantId, $entityId, $unitId, '01', 'Durable capture rollback', 9, 4);

        // Force the final workflow-event append to fail after binary capture,
        // evidence-row insertion and intent linking have occurred.
        DB::table('document_workflow_events')->insert([
            'workflow_id' => $workflow->id,
            'tenant_id' => $tenantId,
            'revision' => 1,
            'action' => 'reserved_revision',
            'from_status' => 'draft',
            'to_status' => 'submitted',
            'actor_user_id' => $actor->getKey(),
            'reason' => 'Test-only conflicting revision',
            'metadata' => null,
            'occurred_at' => now(),
        ]);

        try {
            $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
                'submitted_file_id' => $fileId,
                'submitted_file_version' => 1,
            ]);
            self::fail('The conflicting workflow event must roll back submission.');
        } catch (QueryException) {
            $fresh = $workflow->fresh();
            self::assertSame('draft', $fresh->status);
            self::assertSame(0, (int) $fresh->revision);
            self::assertSame(0, DB::table('document_file_versions')->where('tenant_id', $tenantId)->count());

            $intent = DB::table('document_evidence_capture_intents')
                ->where('tenant_id', $tenantId)->where('file_id', $fileId)->where('file_version', 1)->first();
            self::assertNotNull($intent);
            self::assertSame('captured', $intent->status);
            self::assertSame(hash('sha256', 'immutable source bytes'), $intent->sha256);
            self::assertTrue(Storage::disk('local')->exists($intent->storage_path));
        }
    }

    public function test_cleanup_claim_prevents_a_late_workflow_attachment(): void
    {
        [$tenantId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $lifecycle = $this->app->make(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentEvidenceCaptureLifecycle::class);
        $prepared = $lifecycle->prepare($tenantId, $fileId, 1, (int) $actor->getKey());

        DB::table('document_evidence_capture_intents')->where('id', $prepared['intent_id'])->update([
            'status' => 'cleanup_claimed',
            'cleanup_claim_token' => (string) \Illuminate\Support\Str::uuid(),
            'cleanup_claimed_at' => now(),
            'cleanup_lease_until' => now()->addMinutes(10),
        ]);

        try {
            DB::transaction(fn () => $lifecycle->markLinked($prepared['intent_id'], $tenantId, 1, (int) $actor->getKey()));
            self::fail('A cleanup-claimed intent must not be attached to a workflow.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            self::assertSame('cleanup_claimed', DB::table('document_evidence_capture_intents')
                ->where('id', $prepared['intent_id'])->value('status'));
            self::assertTrue(Storage::disk('local')->exists($prepared['snapshot']['path']));
        }
    }

    public function test_reconciler_deletes_only_expired_unreferenced_capture_and_is_idempotent(): void
    {
        [$tenantId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $lifecycle = $this->app->make(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentEvidenceCaptureLifecycle::class);
        $prepared = $lifecycle->prepare($tenantId, $fileId, 1, (int) $actor->getKey());
        DB::table('document_evidence_capture_intents')->where('id', $prepared['intent_id'])
            ->update(['captured_at' => now()->subDays(2)]);

        $reconciler = $this->app->make(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentEvidenceCaptureReconciler::class);
        $dryRun = $reconciler->run(100, 1440, false);
        self::assertSame(1, $dryRun['eligible']);
        self::assertTrue(Storage::disk('local')->exists($prepared['snapshot']['path']));

        $applied = $reconciler->run(100, 1440, true);
        self::assertSame(1, $applied['deleted']);
        self::assertSame('deleted', DB::table('document_evidence_capture_intents')
            ->where('id', $prepared['intent_id'])->value('status'));
        self::assertFalse(Storage::disk('local')->exists($prepared['snapshot']['path']));

        $again = $reconciler->run(100, 1440, true);
        self::assertSame(0, $again['deleted']);
    }

    public function test_reconciler_preserves_capture_when_committed_evidence_reference_exists(): void
    {
        [$tenantId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());
        $lifecycle = $this->app->make(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentEvidenceCaptureLifecycle::class);
        $prepared = $lifecycle->prepare($tenantId, $fileId, 1, (int) $actor->getKey());
        $snapshot = $prepared['snapshot'];

        DB::table('document_file_versions')->insert([
            'tenant_id' => $tenantId,
            'file_id' => $fileId,
            'file_version' => 1,
            'storage_disk' => $snapshot['disk'],
            'storage_path' => $snapshot['path'],
            'sha256' => $snapshot['sha256'],
            'size' => $snapshot['size'],
            'captured_by_user_id' => $actor->getKey(),
            'captured_at' => now(),
        ]);
        DB::table('document_evidence_capture_intents')->where('id', $prepared['intent_id'])
            ->update(['captured_at' => now()->subDays(2)]);

        $result = $this->app->make(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentEvidenceCaptureReconciler::class)
            ->run(100, 1440, true);
        self::assertSame(0, $result['deleted']);
        self::assertSame('linked', DB::table('document_evidence_capture_intents')
            ->where('id', $prepared['intent_id'])->value('status'));
        self::assertTrue(Storage::disk('local')->exists($snapshot['path']));
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

    /** @return array{key_id:string,signature:string,signed_at:string} */
    private function signatureEvidence(User $signer, int $tenantId, object $workflow): array
    {
        $pair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($pair);
        $secretKey = sodium_crypto_sign_secretkey($pair);
        $keyId = 'test-ed25519-'.$tenantId.'-'.bin2hex(random_bytes(5));
        $signedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        DB::table('document_signing_keys')->insert([
            'tenant_id' => $tenantId,
            'key_id' => $keyId,
            'signer_user_id' => $signer->getKey(),
            'algorithm' => 'Ed25519',
            'public_key_base64' => base64_encode($publicKey),
            'trusted_by_user_id' => $signer->getKey(),
            'trust_reference' => 'Owner-controlled test trust fixture',
            'trusted_at' => now()->subMinute(),
            'valid_until' => null,
        ]);
        $payload = SodiumDocumentSignatureEvidenceVerifier::canonicalPayload(
            $tenantId,
            (string) $workflow->uuid,
            (string) $workflow->submitted_file_sha256,
            (int) $signer->getKey(),
            $signedAt,
        );
        return [
            'key_id' => $keyId,
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secretKey)),
            'signed_at' => $signedAt->format('Y-m-d\TH:i:s\Z'),
        ];
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
        DB::table('document_entity_code_assignments')->insert([
            'tenant_id' => $tenantId, 'legal_entity_id' => $entityId, 'entity_code' => '01',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_department_code_assignments')->insert([
            'tenant_id' => $tenantId, 'legal_entity_id' => $entityId, 'organization_unit_id' => $unitId,
            'entity_code' => '01', 'department_code' => '01', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$tenantId, $entityId, $unitId];
    }

    private function file(int $tenantId, int $userId, bool $isPublic = false): int
    {
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
            'is_public' => $isPublic,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
