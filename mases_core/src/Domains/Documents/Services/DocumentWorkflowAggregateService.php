<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
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
     * @param array{submitted_file_id?:int,submitted_file_version?:int,issued_document_id?:int,replacement_document_id?:int} $evidence
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

                // Capture the bytes while the metadata row is locked. The hash is
                // server-computed and becomes part of the submitted evidence.
                $stream = Storage::disk((string) $file->disk)->readStream((string) $file->path);
                if (! is_resource($stream)) {
                    throw new UnprocessableEntityHttpException('The submitted file binary is unavailable; immutable evidence cannot be established.');
                }
                $hashContext = hash_init('sha256');
                try {
                    if (hash_update_stream($hashContext, $stream) === false) {
                        throw new UnprocessableEntityHttpException('The submitted file binary could not be hashed.');
                    }
                } finally {
                    fclose($stream);
                }
                $fileSha256 = hash_final($hashContext);

                $workflow->submitted_file_id = $fileId;
                $workflow->submitted_file_version = $fileVersion;
                $workflow->submitted_file_sha256 = $fileSha256;
                $metadata = [
                    'submitted_file_id' => $fileId,
                    'submitted_file_version' => $fileVersion,
                    'submitted_file_sha256' => $fileSha256,
                ];
            }

            if ($action === 'issue') {
                // Re-read and hash the submitted bytes at issuance time. A
                // changed path, version, or binary must fail closed rather
                // than allowing the original submission hash to mask drift.
                $submittedFile = DB::table('files')
                    ->where('id', (int) $workflow->submitted_file_id)
                    ->where('tenant_id', $tenantId)
                    ->where('version', (int) $workflow->submitted_file_version)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first(['disk', 'path']);
                if ($submittedFile === null) {
                    throw new UnprocessableEntityHttpException('The submitted immutable file version is no longer available.');
                }
                $submittedStream = Storage::disk((string) $submittedFile->disk)->readStream((string) $submittedFile->path);
                if (! is_resource($submittedStream)) {
                    throw new UnprocessableEntityHttpException('The submitted file binary is unavailable at issuance.');
                }
                $submittedHashContext = hash_init('sha256');
                try {
                    if (hash_update_stream($submittedHashContext, $submittedStream) === false) {
                        throw new UnprocessableEntityHttpException('The submitted file binary could not be revalidated at issuance.');
                    }
                } finally {
                    fclose($submittedStream);
                }
                $currentSubmittedSha256 = hash_final($submittedHashContext);
                if (! is_string($workflow->submitted_file_sha256)
                    || ! hash_equals((string) $workflow->submitted_file_sha256, $currentSubmittedSha256)) {
                    throw new UnprocessableEntityHttpException('The submitted file binary changed after submission; issuance is blocked.');
                }

                $documentId = (int) ($evidence['issued_document_id'] ?? 0);
                $document = Document::query()->where('tenant_id', $tenantId)->whereKey($documentId)
                    ->where('status', 'issued')->lockForUpdate()->first();
                if ($document === null || $documentId < 1
                    || (int) $document->file_id !== (int) $workflow->submitted_file_id
                    || (int) $document->file_version !== (int) $workflow->submitted_file_version
                    || ! is_string($workflow->submitted_file_sha256)
                    || ! hash_equals((string) $workflow->submitted_file_sha256, (string) $document->file_sha256)
                    || (int) $document->legal_entity_id !== (int) $workflow->legal_entity_id
                    || (int) $document->organization_unit_id !== (int) $workflow->organization_unit_id
                    || (string) $document->document_type_number !== (string) $workflow->document_type_number) {
                    throw new UnprocessableEntityHttpException('Issuance must reference an issued record with matching immutable file version and SHA-256, tenant, issuer and document type.');
                }
                $workflow->issued_document_id = $documentId;
                $metadata = ['issued_document_id' => $documentId];
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
