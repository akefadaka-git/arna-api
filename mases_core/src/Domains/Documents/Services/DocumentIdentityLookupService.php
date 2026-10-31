<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use MaaleArna\MASES\Core\Contracts\Documents\DocumentIdentityLookup;
use MaaleArna\MASES\Core\Domains\Documents\Models\Document;

/**
 * Resolves the persisted document identity for a tenant-owned file version.
 *
 * A file can be referenced by more than one historical issuance record. The
 * newest persisted issuance is the presentation identity for that exact file
 * version; ordering is deterministic when issued_at timestamps are equal.
 */
final class DocumentIdentityLookupService implements DocumentIdentityLookup
{
    public function findForFiles(array $fileIds, int|string $tenantId): array
    {
        $ids = [];
        foreach ($fileIds as $fileId) {
            $id = filter_var($fileId, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) {
                $ids[(string) $id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $documents = Document::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('file_id', array_values($ids))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get([
                'file_id',
                'uuid',
                'canonical_number',
                'document_type_code',
                'document_type_number',
                'status',
                'issued_at',
            ]);

        $identities = [];
        foreach ($documents as $document) {
            $key = (string) $document->file_id;
            if (array_key_exists($key, $identities)) {
                continue;
            }

            $identities[$key] = [
                'canonical_number' => (string) $document->canonical_number,
                'document_type_code' => (string) $document->document_type_code,
                'document_type_number' => $document->document_type_number !== null
                    ? (string) $document->document_type_number
                    : null,
                'status' => (string) $document->status,
                'uuid' => (string) $document->uuid,
                'issued_at' => $document->issued_at?->toIso8601String(),
            ];
        }

        return $identities;
    }

    public function findForFile(int|string $fileId, int|string $tenantId): ?array
    {
        return $this->findForFiles([$fileId], $tenantId)[(string) $fileId] ?? null;
    }
}
