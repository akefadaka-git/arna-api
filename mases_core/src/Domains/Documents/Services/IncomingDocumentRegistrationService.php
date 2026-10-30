<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Documents\Models\IncomingDocumentRegistration;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationNumberingRegistryService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class IncomingDocumentRegistrationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContextService $tenantContext,
        private readonly OrganizationNumberingRegistryService $numberingRegistry,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function register(array $input, User $actor, string $idempotencyKey): IncomingDocumentRegistration
    {
        $tenantId = $this->requireTenantId();
        $this->authorize('documents.receive', $actor, $tenantId);

        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) {
            throw new UnprocessableEntityHttpException('A stable Idempotency-Key of 16–128 safe characters is required.');
        }

        $legalEntityId = filter_var($input['legal_entity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $fileId = filter_var($input['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $bbCode = (string) ($input['bb_code'] ?? '');
        $subject = trim((string) ($input['subject'] ?? ''));
        if (!$legalEntityId || !$fileId || $subject === '' || mb_strlen($subject) > 255) {
            throw new UnprocessableEntityHttpException('A legal entity, published file, BB code and subject of 1–255 characters are required.');
        }

        // Do not trim, normalize, parse or replace the sender's reference.
        $senderReference = array_key_exists('sender_reference', $input) && $input['sender_reference'] !== null
            ? (string) $input['sender_reference']
            : null;
        if ($senderReference !== null && mb_strlen($senderReference) > 255) {
            throw new UnprocessableEntityHttpException('Sender reference must not exceed 255 characters.');
        }
        $senderName = isset($input['sender_name']) ? (string) $input['sender_name'] : null;
        $notes = isset($input['notes']) ? (string) $input['notes'] : null;
        $receivedAt = isset($input['received_at'])
            ? CarbonImmutable::parse((string) $input['received_at'])
            : CarbonImmutable::now('Africa/Addis_Ababa');
        $metadata = $this->canonicalize($input['metadata'] ?? []);
        if (!is_array($metadata)) {
            throw new UnprocessableEntityHttpException('Incoming document metadata must be an object.');
        }

        try {
            return DB::transaction(function () use (
                $tenantId, $actor, $idempotencyKey, $legalEntityId, $fileId, $bbCode,
                $subject, $senderReference, $senderName, $notes, $receivedAt, $metadata
            ): IncomingDocumentRegistration {
                $this->authorize('documents.receive', $actor, $tenantId);
                $registry = $this->numberingRegistry->resolveForIssuance(
                    (int) $tenantId, (int) $legalEntityId, $bbCode, true
                );

                // Receiving a file requires both tenant membership and the
                // platform's file.view permission; public visibility is irrelevant.
                $isMember = $actor->tenants()->where('tenant_id', $tenantId)->exists();
                if (!$isMember || !$this->authorization->hasPermission('file.view', $actor, $tenantId)) {
                    throw new AuthorizationException('The actor is not authorized to attach this tenant file.');
                }

                $file = DB::table('files')
                    ->where('id', $fileId)
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first(['id', 'version', 'status']);
                if ($file === null || $file->status !== 'published') {
                    throw new UnprocessableEntityHttpException('The attached file must be a published, non-deleted file in the active tenant.');
                }

                $hashPayload = [
                    'legal_entity_id' => (int) $registry['legal_entity_id'],
                    'organization_unit_id' => (int) $registry['organization_unit_id'],
                    'bb_code' => (string) $registry['bb_code'],
                    'file_id' => (int) $fileId,
                    'subject' => $subject,
                    'sender_name' => $senderName,
                    'sender_reference' => $senderReference,
                    'notes' => $notes,
                    'received_at' => $receivedAt->toIso8601String(),
                    'metadata' => $metadata,
                ];
                $requestHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $existing = IncomingDocumentRegistration::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (!hash_equals((string) $existing->request_hash, $requestHash)) {
                        throw new ConflictHttpException('Idempotency-Key was already used for a different incoming registration.');
                    }
                    return $existing;
                }

                $now = CarbonImmutable::now('Africa/Addis_Ababa');
                $registration = IncomingDocumentRegistration::query()->create([
                    'registration_uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $registry['legal_entity_id'],
                    'organization_unit_id' => $registry['organization_unit_id'],
                    'bb_code' => $registry['bb_code'],
                    'file_id' => (int) $fileId,
                    'file_version' => (int) $file->version,
                    'sender_name' => $senderName,
                    'sender_reference' => $senderReference,
                    'subject' => $subject,
                    'notes' => $notes,
                    'received_at' => $receivedAt,
                    'registered_at' => $now,
                    'registered_by' => $actor->getAuthIdentifier(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'metadata' => $metadata,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('incoming_document_events')->insert([
                    'incoming_document_registration_id' => $registration->getKey(),
                    'tenant_id' => $tenantId,
                    'event_type' => 'registered',
                    'actor_user_id' => $actor->getAuthIdentifier(),
                    'metadata' => json_encode([
                        'registration_uuid' => $registration->registration_uuid,
                        'sender_reference' => $senderReference,
                        'file_id' => (int) $fileId,
                        'file_version' => (int) $file->version,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $registration;
            }, 3);
        } catch (QueryException $exception) {
            $existing = IncomingDocumentRegistration::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_hash, $requestHash ?? '')) {
                    throw new ConflictHttpException('Idempotency-Key was already used for a different incoming registration.');
                }
                return $existing;
            }
            throw $exception;
        }
    }

    public function find(string $registrationUuid, User $actor): IncomingDocumentRegistration
    {
        $tenantId = $this->requireTenantId();
        $this->authorize('documents.incoming.view', $actor, $tenantId);

        return IncomingDocumentRegistration::query()
            ->where('tenant_id', $tenantId)
            ->where('registration_uuid', $registrationUuid)
            ->firstOrFail();
    }

    private function authorize(string $permission, User $actor, int|string $tenantId): void
    {
        if (!$this->authorization->hasPermission($permission, $actor, $tenantId)) {
            throw new AuthorizationException('You are not authorized to perform this incoming-document operation.');
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
