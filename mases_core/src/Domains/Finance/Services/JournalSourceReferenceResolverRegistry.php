<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\JournalSourceReferenceResolver;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;

/**
 * Dispatches source-reference validation to exactly one authoritative resolver.
 *
 * The empty default registry is intentionally safe: external business sources
 * are rejected until a resolver proving tenant and legal-entity ownership is
 * explicitly registered. Ambiguous resolver ownership is also rejected.
 */
final class JournalSourceReferenceResolverRegistry
{
    /** @var list<JournalSourceReferenceResolver> */
    private array $resolvers;

    /**
     * @param iterable<JournalSourceReferenceResolver> $resolvers
     */
    public function __construct(iterable $resolvers = [])
    {
        $this->resolvers = [];

        foreach ($resolvers as $resolver) {
            if (!$resolver instanceof JournalSourceReferenceResolver) {
                throw new \InvalidArgumentException(
                    'Every source-reference resolver must implement JournalSourceReferenceResolver.'
                );
            }

            $this->resolvers[] = $resolver;
        }
    }

    /**
     * Validate external provenance. Internal manual/system entries do not
     * reference a separate business domain and are handled by Finance itself.
     *
     * @throws ValidationException
     */
    public function assertValid(
        JournalSourceType $sourceType,
        ?string $sourceId,
        int $tenantId,
        int $legalEntityId,
    ): void {
        if (!$sourceType->requiresSourceReference()) {
            return;
        }

        if ($sourceId === null || trim($sourceId) === '') {
            throw ValidationException::withMessages([
                'source_id' => 'This source type requires an originating business reference.',
            ]);
        }

        $matches = array_values(array_filter(
            $this->resolvers,
            static fn (JournalSourceReferenceResolver $resolver): bool => $resolver->supports($sourceType),
        ));

        if (count($matches) !== 1) {
            throw ValidationException::withMessages([
                'source_type' => count($matches) === 0
                    ? 'This source type is not enabled because no authoritative source validator is registered.'
                    : 'This source type has ambiguous validators and cannot be used.',
            ]);
        }

        $matches[0]->assertValid($sourceType, trim($sourceId), $tenantId, $legalEntityId);
    }
}
