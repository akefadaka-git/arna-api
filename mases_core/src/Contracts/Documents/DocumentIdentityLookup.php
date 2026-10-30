<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Contracts\Documents;

/**
 * Read-only cross-domain projection of a persisted Documents identity.
 *
 * File Management owns file bytes and publication state; this contract lets
 * presentation/verification consumers display the canonical identity without
 * making File Management depend on Documents' Eloquent models or tables.
 */
interface DocumentIdentityLookup
{
    /**
     * @param array<int, int|string> $fileIds
     * @return array<string, array{
     *     canonical_number: string,
     *     document_type_code: string,
     *     document_type_number: string|null,
     *     status: string,
     *     uuid: string,
     *     issued_at: string|null
     * }>
     */
    public function findForFiles(array $fileIds, int|string $tenantId): array;

    /**
     * @return array{
     *     canonical_number: string,
     *     document_type_code: string,
     *     document_type_number: string|null,
     *     status: string,
     *     uuid: string,
     *     issued_at: string|null
     * }|null
     */
    public function findForFile(int|string $fileId, int|string $tenantId): ?array;
}
