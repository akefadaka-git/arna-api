<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Documents\Models\Document;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class DocumentIssuanceService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContextService $tenantContext,
        private readonly EthiopianCalendar $ethiopianCalendar,
        private readonly DocumentAuthorityService $documentAuthority,
    ) {
    }

    /**
     * Issue a document and allocate its permanent number in the same transaction.
     *
     * A caller must supply a stable Idempotency-Key for safe retries. The
     * canonical number is allocated only after permission, tenant, file and
     * payload checks have passed.
     *
     * @param array<string, mixed> $input
     */
    public function issue(array $input, User $actor, string $idempotencyKey): Document
    {
        $tenantId = $this->requireTenantId();
        $this->authorize('documents.issue', $actor, $tenantId);

        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) {
            throw new UnprocessableEntityHttpException('A stable Idempotency-Key of 16–128 safe characters is required.');
        }

        $legalEntityId = filter_var($input['legal_entity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $fileId = filter_var($input['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $title = trim((string) ($input['title'] ?? ''));

        if (!$legalEntityId || !$fileId || $title === '' || mb_strlen($title) > 255) {
            throw new UnprocessableEntityHttpException('Legal entity, file, and a title of 1–255 characters are required.');
        }

        $registry = $this->documentAuthority->assertCanIssue(
            $actor,
            (int) $tenantId,
            (int) $legalEntityId,
            (string) ($input['organization_code'] ?? ''),
            (string) ($input['department_code'] ?? ''),
            (string) ($input['issuing_role_code'] ?? ''),
            (string) ($input['document_type_code'] ?? ''),
        );

        $file = DB::table('files')
            ->where('id', $fileId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'tenant_id', 'version', 'status', 'is_public']);

        if ($file === null) {
            throw new ModelNotFoundException('The requested file does not exist in the active tenant.');
        }
        if ($file->status !== 'published') {
            throw new UnprocessableEntityHttpException('Only a published file version can be issued as an official document.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa'));
        $ecYear = (int) $this->ethiopianCalendar->toEthiopian($now)['year'];
        $metadata = $this->canonicalize($input['metadata'] ?? []);
        if (!is_array($metadata)) {
            throw new UnprocessableEntityHttpException('Document metadata must be an object.');
        }

        $payload = [
            'legal_entity_id' => $registry['legal_entity_id'],
            'organization_unit_id' => $registry['organization_unit_id'],
            'governance_body_id' => $registry['governance_body_id'],
            'governance_position_id' => $registry['governance_position_id'],
            'organization_code' => $registry['organization_code'],
            'document_type_code' => $registry['document_type_code'],
            'department_code' => $registry['department_code'],
            'issuing_role_code' => $registry['issuing_role_code'],
            'title' => $title,
            'file_id' => (int) $fileId,
            'file_version' => (int) $file->version,
            'metadata' => $metadata,
            'ec_year' => $ecYear,
        ];
        $hashPayload = $payload;
        unset($hashPayload['ec_year'], $hashPayload['file_version']);
        $requestHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            return DB::transaction(function () use ($tenantId, $actor, $idempotencyKey, $requestHash, $payload, $now): Document {
            $existing = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new ConflictHttpException('Idempotency-Key was already used for a different issuance request.');
                }

                return $existing;
            }

            // Re-lock and revalidate the exact file row in the issuance
            // transaction so publication/version cannot change between the
            // preflight check and permanent document-number allocation.
            $lockedFile = DB::table('files')
                ->where('id', $payload['file_id'])
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'version', 'status']);

            if ($lockedFile === null || $lockedFile->status !== 'published') {
                throw new ConflictHttpException('The linked file is no longer published in the active tenant.');
            }
            if ((int) $lockedFile->version !== (int) $payload['file_version']) {
                throw new ConflictHttpException('The linked file version changed during issuance; retry against the current published version.');
            }

            $scope = [
                'tenant_id' => $tenantId,
                'legal_entity_id' => $payload['legal_entity_id'],
                'organization_unit_id' => $payload['organization_unit_id'],
                'governance_position_id' => $payload['governance_position_id'],
                'document_type_code' => $payload['document_type_code'],
                'ec_year' => $payload['ec_year'],
            ];

            // insertOrIgnore creates the unique scope row safely under concurrency;
            // the subsequent row lock serializes allocation for this exact scope.
            DB::table('document_number_counters')->insertOrIgnore($scope + [
                'organization_code' => $payload['organization_code'],
                'governance_body_id' => $payload['governance_body_id'],
                'department_code' => $payload['department_code'],
                'issuing_role_code' => $payload['issuing_role_code'],
                'next_sequence' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $counter = DB::table('document_number_counters')
                ->where($scope)
                ->lockForUpdate()
                ->first();

            if ($counter === null || !(bool) $counter->is_active) {
                throw new ConflictHttpException('The numbering scope is unavailable or disabled.');
            }

            if (!hash_equals((string) $counter->organization_code, $payload['organization_code'])
                || !hash_equals((string) $counter->department_code, $payload['department_code'])
                || !hash_equals((string) $counter->issuing_role_code, $payload['issuing_role_code'])
                || (int) $counter->governance_body_id !== $payload['governance_body_id']) {
                throw new ConflictHttpException('A canonical code or Governance scope changed within an existing numbering scope; an explicit migration of the numbering scope is required.');
            }

            $sequence = (int) $counter->next_sequence;
            if ($sequence < 1 || $sequence > 999999) {
                throw new ConflictHttpException('The numbering scope has exhausted its six-digit sequence.');
            }

            DB::table('document_number_counters')->where('id', $counter->id)->update([
                'next_sequence' => $sequence + 1,
                'updated_at' => $now,
            ]);

            $number = sprintf(
                '%s-%s-%s-%s-%06d-EC%04d',
                $payload['organization_code'],
                $payload['document_type_code'],
                $payload['department_code'],
                $payload['issuing_role_code'],
                $sequence,
                $payload['ec_year'],
            );

            $document = Document::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'legal_entity_id' => $payload['legal_entity_id'],
                'organization_unit_id' => $payload['organization_unit_id'],
                'governance_body_id' => $payload['governance_body_id'],
                'governance_position_id' => $payload['governance_position_id'],
                'organization_code' => $payload['organization_code'],
                'document_type_code' => $payload['document_type_code'],
                'department_code' => $payload['department_code'],
                'issuing_role_code' => $payload['issuing_role_code'],
                'ec_year' => $payload['ec_year'],
                'sequence_number' => $sequence,
                'canonical_number' => $number,
                'title' => $payload['title'],
                'file_id' => $payload['file_id'],
                'file_version' => $payload['file_version'],
                'status' => 'issued',
                'request_hash' => $requestHash,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $payload['metadata'],
                'issued_by' => $actor->getAuthIdentifier(),
                'issued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->appendLifecycleEvent($document, $actor, 'issued', null, $now);

            return $document;
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent retry can race the initial idempotency lookup. The
            // unique tenant/key constraint is authoritative; resolve the winner
            // after rollback instead of leaking a duplicate-key 500 response.
            $existing = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new ConflictHttpException('Idempotency-Key was already used for a different issuance request.');
                }

                return $existing;
            }

            throw $exception;
        }
    }

    public function findForCurrentTenant(int $documentId, User $actor): Document
    {
        $tenantId = $this->requireTenantId();
        $this->authorize('documents.view', $actor, $tenantId);

        return Document::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($documentId);
    }

    public function void(int $documentId, string $reason, User $actor): Document
    {
        return $this->transition($documentId, $reason, $actor, 'documents.void', 'voided');
    }

    public function revoke(int $documentId, string $reason, User $actor): Document
    {
        return $this->transition($documentId, $reason, $actor, 'documents.revoke', 'revoked');
    }

    private function transition(int $documentId, string $reason, User $actor, string $permission, string $targetStatus): Document
    {
        $tenantId = $this->requireTenantId();
        $this->authorize($permission, $actor, $tenantId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new UnprocessableEntityHttpException('A reason of 1–1000 characters is required.');
        }

        return DB::transaction(function () use ($documentId, $reason, $actor, $tenantId, $targetStatus): Document {
            $document = Document::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== 'issued') {
                throw new ConflictHttpException('Only an issued document can be voided or revoked.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa'));
            $fields = $targetStatus === 'voided'
                ? ['status' => 'voided', 'voided_by' => $actor->getAuthIdentifier(), 'voided_at' => $now, 'void_reason' => $reason]
                : ['status' => 'revoked', 'revoked_by' => $actor->getAuthIdentifier(), 'revoked_at' => $now, 'revoke_reason' => $reason];

            $document->forceFill($fields + ['updated_at' => $now])->save();
            $this->appendLifecycleEvent($document, $actor, $targetStatus, $reason, $now);

            return $document->refresh();
        }, 3);
    }

    private function appendLifecycleEvent(Document $document, User $actor, string $eventType, ?string $reason, DateTimeImmutable $occurredAt): void
    {
        DB::table('document_lifecycle_events')->insert([
            'document_id' => $document->getKey(),
            'tenant_id' => $document->tenant_id,
            'event_type' => $eventType,
            'actor_user_id' => $actor->getAuthIdentifier(),
            'reason' => $reason,
            'metadata' => json_encode(['canonical_number' => $document->canonical_number], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function authorize(string $permission, User $actor, int|string $tenantId): void
    {
        if (!$this->authorization->hasPermission($permission, $actor, $tenantId)) {
            throw new AuthorizationException('You are not authorized to perform this document operation.');
        }
    }

    private function requireTenantId(): int|string
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null || $tenantId === '') {
            throw new AuthorizationException('A verified tenant context is required.');
        }

        return $tenantId;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
