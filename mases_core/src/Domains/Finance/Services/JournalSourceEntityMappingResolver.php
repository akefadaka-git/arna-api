<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\JournalSourceReferenceResolver;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalSourceEntityMapping;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * Validates source records and their explicit Finance legal-entity mapping.
 *
 * Only source types with a known authoritative Eloquent model are supported.
 * Other source types remain disabled until their owning domains supply and
 * register a resolver with equivalent ownership and eligibility guarantees.
 */
final class JournalSourceEntityMappingResolver implements JournalSourceReferenceResolver
{
    public function supports(JournalSourceType $sourceType): bool
    {
        return in_array($sourceType, [
            JournalSourceType::SHAREHOLDER,
            JournalSourceType::WALLET,
            JournalSourceType::LOAN,
        ], true);
    }

    public function assertValid(
        JournalSourceType $sourceType,
        string $sourceId,
        int $tenantId,
        int $legalEntityId,
    ): void {
        $sourceId = trim($sourceId);

        if ($sourceId === '' || $tenantId < 1 || $legalEntityId < 1 || !$this->supports($sourceType)) {
            $this->rejectReference();
        }

        $sourceModel = $this->sourceQuery($sourceType);
        $sourceExists = $sourceModel
            ->where('tenant_id', $tenantId)
            ->whereKey($sourceId)
            ->exists();

        if (!$sourceExists) {
            $this->rejectReference();
        }

        $mappingExists = JournalSourceEntityMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->where('status', JournalSourceEntityMapping::STATUS_APPROVED)
            ->whereNotNull('approved_by')
            ->whereNotNull('approved_at')
            ->whereHas('entity', static function ($query) use ($tenantId, $legalEntityId): void {
                $query->where('tenant_id', $tenantId)
                    ->whereKey($legalEntityId)
                    ->where('status', 'active');
            })
            ->exists();

        if (!$mappingExists) {
            $this->rejectReference();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function sourceQuery(JournalSourceType $sourceType)
    {
        $modelClass = match ($sourceType) {
            JournalSourceType::SHAREHOLDER => Shareholder::class,
            JournalSourceType::WALLET => Wallet::class,
            JournalSourceType::LOAN => Loan::class,
            default => null,
        };

        if ($modelClass === null) {
            $this->rejectReference();
        }

        return $modelClass::query();
    }

    private function rejectReference(): never
    {
        throw ValidationException::withMessages([
            'source_id' => 'The originating record is unavailable or is not approved for this legal entity.',
        ]);
    }
}
