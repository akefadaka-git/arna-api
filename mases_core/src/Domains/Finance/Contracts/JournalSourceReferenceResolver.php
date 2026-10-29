<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;

/**
 * Contract for validating an originating business record before Finance
 * accepts it as journal provenance.
 *
 * Implementations MUST resolve the source using its owning domain's
 * authoritative model/service. A source ID by itself is never proof of
 * ownership or authorization.
 *
 * Implementations MUST fail closed unless they can establish all of:
 * - the source record exists and is eligible to be referenced;
 * - the source belongs to the supplied tenant;
 * - the source is explicitly mapped to the supplied Finance legal entity;
 * - the mapping is authoritative and cannot be inferred from tenant equality.
 *
 * Finance owns the journal, not the originating business record. Resolvers
 * must not create, update, or delete source-domain records.
 *
 * Until a source type has a resolver that can prove the full contract,
 * validation must reject it rather than silently accepting an unverified ID.
 */
interface JournalSourceReferenceResolver
{
    /**
     * Whether this resolver supports the source type.
     */
    public function supports(JournalSourceType $sourceType): bool;

    /**
     * Validate the source record against the journal's complete scope.
     *
     * Implementations should throw a field-scoped ValidationException when
     * the reference is missing, unavailable, cross-tenant, or not mapped to
     * the requested legal entity. They must not reveal cross-tenant record
     * details in the resulting message.
     *
     * @throws ValidationException
     */
    public function assertValid(
        JournalSourceType $sourceType,
        string $sourceId,
        int $tenantId,
        int $legalEntityId,
    ): void;
}
