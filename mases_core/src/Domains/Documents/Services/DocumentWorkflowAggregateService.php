<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentSignatureEvidenceVerifier;
use MaaleArna\MASES\Core\Domains\Documents\Models\Document;
use MaaleArna\MASES\Core\Domains\Documents\Models\DocumentLifecycleEvent;
use MaaleArna\MASES\Core\Domains\Documents\Models\DocumentWorkflowAggregate;
use MaaleArna\MASES\Core\Domains\Documents\Models\DocumentWorkflowEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Transactional aggregate boundary for the formal Documents lifecycle.
 *
 * Every mutation locks the tenant-scoped aggregate, checks its expected
 * revision, authorizes the actor, changes one legal state and appends one
 * immutable event in the same transaction.
 */
final class DocumentWorkflowAggregateService
{
    /** @var array<string, array{from:list<string>,to:string,permission:string,governed:bool,reason:bool}> */
    private const TRANSITIONS = [
        'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'documents.submit', 'governed' => false, 'reason' => false],
        'start_review' => ['from' => ['submitted'], 'to' => 'in_review', 'permission' => 'documents.review', 'governed' => true, 'reason' => false],
        'return' => ['from' => ['in_review'], 'to' => 'returned', 'permission' => 'documents.return', 'governed' => true, 'reason' => true],
        'reject' => ['from' => ['in_review'], 'to' => 'rejected', 'permission' => 'documents.reject', 'governed' => true, 'reason' => true],
        'approve' => ['from' => ['in_review'], 'to' => 'approved', 'permission' => 'documents.approve', 'governed' => true, 'reason' => false],
        'return_to_draft' => ['from' => ['returned'], 'to' => 'draft', 'permission' => 'documents.update', 'governed' => false, 'reason' => false],
        'begin_signing' => ['from' => ['approved'], 'to' => 'signing', 'permission' => 'documents.sign', 'governed' => true, 'reason' => false],
        'issue' => ['from' => ['signing'], 'to' => 'issued', 'permission' => 'documents.issue', 'governed' => true, 'reason' => false],
        'withdraw' => ['from' => ['submitted', 'in_review'], 'to' => 'withdrawn', 'permission' => 'documents.withdraw', 'governed' => true, 'reason' => true],
        'revoke' => ['from' => ['issued'], 'to' => 'revoked', 'permission' => 'documents.revoke', 'governed' => true, 'reason' => true],
        'supersede' => ['from' => ['issued'], 'to' => 'superseded', 'permission' => 'documents.supersede', 'governed' => true, 'reason' => true],
    ];

    public function __construct(
        private readonly DocumentWorkflowAuthorizer $authorization,
        private readonly DocumentNumberAllocator $numberAllocator,
        private readonly EthiopianDocumentYearResolver $yearResolver,
        private readonly ImmutableDocumentBinaryStore $immutableFiles,
        private readonly DocumentSignatureEvidenceVerifier $signatureVerifier,
    ) {}

    public function createDraft(
        User $actor,
        int $tenantId,
        int $legalEntityId,
        int $organizationUnitId,
        string $documentTypeNumber,
        string $title,
        ?int $governanceBodyId = null,
        ?int $governancePositionId = null,
    ): DocumentWorkflowAggregate {
        $this->authorization->requirePermission($actor, $tenantId, 'documents.create');

        if ($tenantId < 1 || $legalEntityId < 1 || $organizationUnitId < 1
            || ! preg_match('/^(0[1-9]|[1-9][0-9])$/', $documentTypeNumber)
            || trim($title) === '') {
            throw new UnprocessableEntityHttpException('A valid tenant, issuer, active document type and non-empty title are required.');
        }

        return DB::transaction(function () use (
            $actor, $tenantId, $legalEntityId, $organizationUnitId,
            $documentTypeNumber, $title, $governanceBodyId, $governancePositionId,
        ): DocumentWorkflowAggregate {
            $entity = DB::table('legal_entities')->where('tenant_id', $tenantId)
                ->where('id', $legalEntityId)->where('status', 'active')->exists();
            $unit = DB::table('organization_units')->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)->where('id', $organizationUnitId)
                ->where('status', 'active')->whereNull('deleted_at')->exists();
            $type = DB::table('document_type_catalogue')->where('numeric_code', $documentTypeNumber)
                ->where('is_active', true)->exists();

            if (! $entity || ! $unit || ! $type) {
                throw new UnprocessableEntityHttpException('The issuer and document type must be active in the validated tenant.');
            }

            if (($governanceBodyId === null) !== ($governancePositionId === null)) {
                throw new UnprocessableEntityHttpException('Governance body and position must be supplied together.');
            }

            $now = now();
            $workflow = DocumentWorkflowAggregate::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'organization_unit_id' => $organizationUnitId,
                'governance_body_id' => $governanceBodyId,
                'governance_position_id' => $governancePositionId,
                'author_user_id' => (int) $actor->getKey(),
                'document_type_number' => $documentTypeNumber,
                'title' => trim($title),
                'status' => 'draft',
                'revision' => 0,
                'last_transition_at' => $now,
            ]);

            $this->appendEvent($workflow, $actor, 'create', null, 'draft', null, ['uuid' => $workflow->uuid], $now);

            return $workflow->refresh();
        });
    }

    /**
     * @param array{submitted_file_id?:int,submitted_file_version?:int,signature_evidence?:array{key_id:string,signature:string,signed_at:string},replaces_document_id?:int,replacement_document_id?:int} $evidence
     */
    public function transition(
        User $actor,
        int $tenantId,
        int $workflowId,
        string $action,
        int $expectedRevision,
        ?string $reason = null,
        array $evidence = [],
    ): DocumentWorkflowAggregate {
        $definition = self::TRANSITIONS[$action] ?? null;
        if ($definition === null) {
            throw new UnprocessableEntityHttpException('Unknown document workflow action.');
        }

        return DB::transaction(function () use (
            $actor, $tenantId, $workflowId, $action, $expectedRevision, $reason, $evidence, $definition,
        ): DocumentWorkflowAggregate {
            $workflow = DocumentWorkflowAggregate::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($workflowId)
                ->lockForUpdate()
                ->first();

            if ($workflow === null) {
                throw (new ModelNotFoundException())->setModel(DocumentWorkflowAggregate::class, [$workflowId]);
            }

            if ((int) $workflow->revision !== $expectedRevision) {
                throw new ConflictHttpException('The document workflow changed; reload it before retrying this action.');
            }

            if (! in_array($workflow->status, $definition['from'], true)) {
                throw new ConflictHttpException('This action is not valid from the current document workflow state.');
            }

            if ($action === 'return_to_draft' && (int) $workflow->author_user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the document author may resume a returned draft.');
            }

            if ($definition['reason'] && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A substantive reason is required for this transition.']);
            }

            if ($definition['governed']) {
                if ($workflow->governance_body_id === null || $workflow->governance_position_id === null) {
                    throw new AuthorizationException('A governed workflow action requires an explicitly assigned Governance body and position.');
                }

                $this->authorization->requireGovernedAction(
                    $actor,
                    $tenantId,
                    (int) $workflow->governance_body_id,
                    (int) $workflow->governance_position_id,
                    $definition['permission'],
                    (int) $workflow->author_user_id,
                );
            } else {
                $this->authorization->requirePermission($actor, $tenantId, $definition['permission']);
            }

            $now = now();
            $metadata = [];

            if ($action === 'submit') {
                $fileId = (int) ($evidence['submitted_file_id'] ?? 0);
                $fileVersion = (int) ($evidence['submitted_file_version'] ?? 0);
                $file = DB::table('files')->where('id', $fileId)->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')->where('version', $fileVersion)
                    ->lockForUpdate()->first(['id', 'version', 'disk', 'path']);
                if ($file === null || $fileId < 1 || $fileVersion < 1) {
                    throw new UnprocessableEntityHttpException('Submission must bind an existing, non-deleted file version owned by the same tenant.');
                }

                // Snapshot the selected File Management version into a
                // content-addressed, write-once evidence object. The database
                // record is append-only and tied to tenant/file/version.
                $snapshot = $this->immutableFiles->capture(
                    $tenantId,
                    $fileId,
                    $fileVersion,
                    (string) $file->disk,
                    (string) $file->path,
                );
                $fileSha256 = $snapshot['sha256'];
                $existingEvidence = DB::table('document_file_versions')
                    ->where('tenant_id', $tenantId)
                    ->where('file_id', $fileId)
                    ->where('file_version', $fileVersion)
                    ->lockForUpdate()
                    ->first();
                if ($existingEvidence !== null) {
                    if (! hash_equals((string) $existingEvidence->sha256, $fileSha256)
                        || (string) $existingEvidence->storage_path !== (string) $snapshot['path']
                        || (int) $existingEvidence->size !== (int) $snapshot['size']) {
                        throw new UnprocessableEntityHttpException('This File Management version was already sealed with different bytes; create a new File Management version before submission.');
                    }
                    $evidenceVersionId = (int) $existingEvidence->id;
                } else {
                    $evidenceVersionId = (int) DB::table('document_file_versions')->insertGetId([
                        'tenant_id' => $tenantId,
                        'file_id' => $fileId,
                        'file_version' => $fileVersion,
                        'storage_disk' => $snapshot['disk'],
                        'storage_path' => $snapshot['path'],
                        'sha256' => $fileSha256,
                        'size' => $snapshot['size'],
                        'captured_by_user_id' => $actor->getKey(),
                        'captured_at' => $now,
                    ]);
                }

                $workflow->submitted_file_id = $fileId;
                $workflow->submitted_file_version = $fileVersion;
                $workflow->submitted_file_sha256 = $fileSha256;
                $workflow->submitted_file_evidence_id = $evidenceVersionId;
                $metadata = [
                    'submitted_file_id' => $fileId,
                    'submitted_file_version' => $fileVersion,
                    'submitted_file_sha256' => $fileSha256,
                    'submitted_file_evidence_id' => $evidenceVersionId,
                ];
            }

            if ($action === 'issue') {
                $immutableVersion = DB::table('document_file_versions')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $workflow->submitted_file_evidence_id)
                    ->where('file_id', (int) $workflow->submitted_file_id)
                    ->where('file_version', (int) $workflow->submitted_file_version)
                    ->lockForUpdate()
                    ->first();
                if ($immutableVersion === null
                    || ! is_string($workflow->submitted_file_sha256)
                    || ! hash_equals((string) $workflow->submitted_file_sha256, (string) $immutableVersion->sha256)
                    || ! $this->immutableFiles->verify(
                        (string) $immutableVersion->storage_disk,
                        (string) $immutableVersion->storage_path,
                        (string) $immutableVersion->sha256,
                        (int) $immutableVersion->size,
                    )) {
                    throw new UnprocessableEntityHttpException('The immutable submitted binary is missing or has changed; issuance is blocked.');
                }

                // The signature binds the exact immutable binary, tenant,
                // workflow and signing identity. A caller cannot supply a
                // pre-created Document row or claim a signature is valid.
                $verifiedSignature = $this->signatureVerifier->verify(
                    $actor,
                    $tenantId,
                    (string) $workflow->uuid,
                    (string) $immutableVersion->sha256,
                    (array) ($evidence['signature_evidence'] ?? []),
                );

                // Allocation, issued-row creation, signature evidence, workflow
                // transition and both event records share this DB transaction.
                $issuedAt = $now;
                $ethiopianYear = $this->yearResolver->forInstant($issuedAt);
                $number = $this->numberAllocator->allocate(
                    $tenantId,
                    (int) $workflow->legal_entity_id,
                    (int) $workflow->organization_unit_id,
                    (string) $workflow->document_type_number,
                    $ethiopianYear,
                );

                $signatureEvidenceId = (int) DB::table('document_signature_evidence')->insertGetId([
                    'tenant_id' => $tenantId,
                    'workflow_id' => $workflow->getKey(),
                    'signing_key_id' => $verifiedSignature['key_id'],
                    'signer_user_id' => $actor->getKey(),
                    'algorithm' => 'Ed25519',
                    'signature_base64' => $verifiedSignature['signature'],
                    'payload_sha256' => $verifiedSignature['payload_sha256'],
                    'signed_at' => $verifiedSignature['signed_at'],
                    'verified_at' => $issuedAt,
                ]);

                $replacesDocumentId = isset($evidence['replaces_document_id'])
                    ? (int) $evidence['replaces_document_id']
                    : null;
                if ($replacesDocumentId !== null) {
                    $original = Document::query()->where('tenant_id', $tenantId)
                        ->whereKey($replacesDocumentId)->where('status', 'issued')
                        ->lockForUpdate()->first();
                    if ($original === null
                        || (int) $original->legal_entity_id !== (int) $workflow->legal_entity_id
                        || (int) $original->organization_unit_id !== (int) $workflow->organization_unit_id
                        || (string) $original->document_type_number !== (string) $workflow->document_type_number
                        || Document::query()->where('tenant_id', $tenantId)
                            ->where('replaces_document_id', $replacesDocumentId)->exists()) {
                        throw new UnprocessableEntityHttpException('A replacement must reference one active issued document in the same tenant, issuer and document type, with no prior replacement.');
                    }
                }

                $document = Document::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $workflow->legal_entity_id,
                    'organization_unit_id' => $workflow->organization_unit_id,
                    'governance_body_id' => $workflow->governance_body_id,
                    'governance_position_id' => $workflow->governance_position_id,
                    'entity_code' => $number['entity_code'],
                    'department_code' => $number['department_code'],
                    'document_type_code' => $number['document_type_code'],
                    'document_type_number' => $number['document_type_number'],
                    'ec_year' => $number['ec_year'],
                    'sequence_number' => $number['sequence_number'],
                    'canonical_number' => $number['canonical_number'],
                    'replaces_document_id' => $replacesDocumentId,
                    'title' => $workflow->title,
                    'file_id' => $workflow->submitted_file_id,
                    'file_version' => $workflow->submitted_file_version,
                    'file_sha256' => $immutableVersion->sha256,
                    'immutable_file_version_id' => $immutableVersion->id,
                    'signature_evidence_id' => $signatureEvidenceId,
                    'status' => 'issued',
                    'request_hash' => hash('sha256', $verifiedSignature['payload_sha256'] . '|' . $number['canonical_number']),
                    'idempotency_key' => 'document-workflow:' . $workflow->uuid,
                    'metadata' => [
                        'workflow_uuid' => $workflow->uuid,
                        'immutable_file_version_id' => (int) $immutableVersion->id,
                        'signature_evidence_id' => $signatureEvidenceId,
                        'signature_key_id' => $verifiedSignature['key_id'],
                        'signature_payload_sha256' => $verifiedSignature['payload_sha256'],
                    ],
                    'issued_by' => $actor->getKey(),
                    'issued_at' => $issuedAt,
                ]);

                DB::table('document_lifecycle_events')->insert([
                    'document_id' => $document->getKey(),
                    'tenant_id' => $tenantId,
                    'event_type' => 'issued',
                    'actor_user_id' => $actor->getKey(),
                    'reason' => null,
                    'metadata' => json_encode([
                        'workflow_id' => $workflow->getKey(),
                        'canonical_number' => $number['canonical_number'],
                        'signature_evidence_id' => $signatureEvidenceId,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'occurred_at' => $issuedAt,
                    'created_at' => $issuedAt,
                    'updated_at' => $issuedAt,
                ]);

                $workflow->issued_document_id = $document->getKey();
                $metadata = [
                    'issued_document_id' => (int) $document->getKey(),
                    'canonical_number' => $number['canonical_number'],
                    'signature_evidence_id' => $signatureEvidenceId,
                    'file_sha256' => $immutableVersion->sha256,
                ];
            }

            if ($action === 'revoke') {
                $issued = Document::query()->where('tenant_id', $tenantId)
                    ->whereKey((int) $workflow->issued_document_id)->where('status', 'issued')
                    ->lockForUpdate()->first();
                if ($issued === null) {
                    throw new ConflictHttpException('The linked issued document is missing or is no longer revocable.');
                }
                $issued->status = 'revoked';
                $issued->revoked_by = $actor->getKey();
                $issued->revoked_at = $now;
                $issued->revoke_reason = trim((string) $reason);
                $issued->save();
                DocumentLifecycleEvent::query()->create([
                    'document_id' => $issued->getKey(),
                    'tenant_id' => $tenantId,
                    'event_type' => 'revoked',
                    'actor_user_id' => $actor->getKey(),
                    'reason' => trim((string) $reason),
                    'metadata' => ['workflow_id' => $workflow->getKey()],
                    'occurred_at' => $now,
                ]);
            }

            if ($action === 'supersede') {
                $replacementId = (int) ($evidence['replacement_document_id'] ?? 0);
                $originalId = (int) $workflow->issued_document_id;
                // Lock both rows in deterministic primary-key order. This
                // serializes supersession against concurrent revocation or another
                // replacement attempt and prevents stale status decisions.
                $lockedDocuments = Document::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', array_values(array_unique([$originalId, $replacementId])))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $original = $lockedDocuments->get($originalId);
                $replacement = $lockedDocuments->get($replacementId);
                if ($originalId < 1 || $replacementId < 1 || $original === null || $replacement === null
                    || (string) $original->status !== 'issued'
                    || (string) $replacement->status !== 'issued'
                    || (int) $replacement->replaces_document_id !== $originalId) {
                    throw new UnprocessableEntityHttpException('Supersession requires a separately issued replacement that explicitly references the original document.');
                }
                $original->status = 'superseded';
                $original->save();
                DocumentLifecycleEvent::query()->create([
                    'document_id' => $original->getKey(),
                    'tenant_id' => $tenantId,
                    'event_type' => 'superseded',
                    'actor_user_id' => $actor->getKey(),
                    'reason' => trim((string) $reason),
                    'metadata' => ['workflow_id' => $workflow->getKey(), 'replacement_document_id' => $replacementId],
                    'occurred_at' => $now,
                ]);
                $metadata = ['replacement_document_id' => $replacementId];
            }

            $from = (string) $workflow->status;
            $workflow->status = $definition['to'];
            $workflow->revision = (int) $workflow->revision + 1;
            $workflow->last_transition_at = $now;
            $workflow->save();

            $this->appendEvent($workflow, $actor, $action, $from, $definition['to'], $reason, $metadata, $now);

            return $workflow->refresh();
        });
    }

    private function appendEvent(
        DocumentWorkflowAggregate $workflow,
        User $actor,
        string $action,
        ?string $from,
        string $to,
        ?string $reason,
        array $metadata,
        mixed $occurredAt,
    ): void {
        DocumentWorkflowEvent::query()->create([
            'workflow_id' => $workflow->getKey(),
            'tenant_id' => $workflow->tenant_id,
            'revision' => $workflow->revision,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->getKey(),
            'reason' => $reason === null ? null : trim($reason),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }
}
