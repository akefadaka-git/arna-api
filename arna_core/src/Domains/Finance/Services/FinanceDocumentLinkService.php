<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Finance\Models\FinanceDocumentLink;

/**
 * Links an issued canonical Documents record to a Finance source record.
 *
 * Documents owns numbering, workflow, binary versions and signatures.
 * Finance owns only the scoped reference and its accounting relationship.
 */
final class FinanceDocumentLinkService
{
    private const PURPOSES = [
        'source_evidence',
        'approval_evidence',
        'supporting_evidence',
        'issued_output',
        'reconciliation_evidence',
        'legal_authority',
    ];

    /**
     * @param array<string, mixed> $metadata
     */
    public function linkIssuedDocument(
        int $tenantId,
        int $legalEntityId,
        int $documentId,
        string $sourceDomain,
        string $sourceType,
        string $sourceId,
        string $purpose,
        ?int $actorId = null,
        array $metadata = [],
    ): FinanceDocumentLink {
        $registry = require dirname(__DIR__) . '/Config/phase01_domain_integrations.php';
        $sourceDomain = strtolower(trim($sourceDomain));
        $sourceType = trim($sourceType);
        $sourceId = trim($sourceId);
        $purpose = strtolower(trim($purpose));

        if ($tenantId < 1 || $legalEntityId < 1 || $documentId < 1
            || ! isset($registry['domains'][$sourceDomain])
            || $sourceType === '' || mb_strlen($sourceType) > 64
            || $sourceId === '' || mb_strlen($sourceId) > 128
            || ! in_array($purpose, self::PURPOSES, true)) {
            throw ValidationException::withMessages([
                'document' => 'A valid tenant, entity, registered source domain, source identity and document purpose are required.',
            ]);
        }

        return DB::transaction(function () use (
            $tenantId, $legalEntityId, $documentId, $sourceDomain,
            $sourceType, $sourceId, $purpose, $actorId, $metadata,
        ): FinanceDocumentLink {
            $entity = DB::table('legal_entities')
                ->where('tenant_id', $tenantId)
                ->where('id', $legalEntityId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($entity === null) {
                throw (new ModelNotFoundException())->setModel(
                    \\MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\LegalEntity::class,
                    [$legalEntityId],
                );
            }

            $document = DB::table('documents')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->where('id', $documentId)
                ->where('status', 'issued')
                ->whereNull('voided_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                throw ValidationException::withMessages([
                    'document_id' => 'The document must be issued, active and belong to the same tenant and legal entity.',
                ]);
            }

            return FinanceDocumentLink::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $legalEntityId,
                    'document_id' => $documentId,
                    'source_domain' => $sourceDomain,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'purpose' => $purpose,
                ],
                [
                    'is_primary' => false,
                    'linked_by' => $actorId,
                    'linked_at' => now(),
                    'metadata' => $metadata,
                ],
            );
        });
    }
}
