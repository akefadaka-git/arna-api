<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceConsolidationAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;

final class FinanceConsolidationMappingService
{
    public function propose(int $tenantId, int $actorId, int $legalEntityId, int $ledgerAccountId, string $consolidationCode, string $reason): FinanceConsolidationAccountMapping
    {
        $code = strtoupper(trim($consolidationCode));
        $reason = trim($reason);
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/', $code)) {
            throw ValidationException::withMessages(['consolidation_account_code' => 'Use a stable canonical code containing only letters, digits, dot, underscore, or hyphen.']);
        }
        if ($reason === '') {
            throw ValidationException::withMessages(['mapping_reason' => 'A mapping rationale is required.']);
        }

        $entity = LegalEntity::query()->where('tenant_id', $tenantId)->where('status', 'active')->findOrFail($legalEntityId);
        try {
            return DB::transaction(function () use ($tenantId, $actorId, $entity, $ledgerAccountId, $code, $reason): FinanceConsolidationAccountMapping {
                // Serialize proposals and reviews for this entity account. Locking
                // the parent account avoids races when no proposal row exists yet.
                $account = LedgerAccount::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entity->id)->lockForUpdate()->findOrFail($ledgerAccountId);

                $activeProposalExists = FinanceConsolidationAccountMapping::query()
                    ->where('tenant_id', $tenantId)->where('legal_entity_id', $entity->id)
                    ->where('ledger_account_id', $account->id)
                    ->whereIn('status', [FinanceConsolidationAccountMapping::PENDING, FinanceConsolidationAccountMapping::APPROVED])
                    ->exists();
                if ($activeProposalExists) {
                    throw ValidationException::withMessages([
                        'ledger_account_id' => 'A pending or approved mapping already exists. Review the pending proposal or use the governed remapping workflow.',
                    ]);
                }

                $version = ((int) FinanceConsolidationAccountMapping::query()
                    ->where('tenant_id', $tenantId)->where('legal_entity_id', $entity->id)
                    ->where('ledger_account_id', $account->id)->max('proposal_version')) + 1;

                return FinanceConsolidationAccountMapping::query()->create([
                    'tenant_id' => $tenantId, 'legal_entity_id' => $entity->id,
                    'ledger_account_id' => $account->id, 'consolidation_account_code' => $code,
                    'account_type' => (string) $account->account_type,
                    'status' => FinanceConsolidationAccountMapping::PENDING, 'proposal_version' => $version,
                    'created_by' => $actorId, 'mapping_reason' => $reason,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $message = strtolower($exception->getMessage());
            $isUniqueViolation = $sqlState === '23505' || $driverCode === 1062
                || ($driverCode === 19 && str_contains($message, 'unique constraint'));
            if (!$isUniqueViolation) throw $exception;
            throw ValidationException::withMessages(['ledger_account_id' => 'A competing mapping proposal was created. Reload the account mapping before retrying.']);
        }
    }

    public function approve(int $tenantId, int $actorId, int $mappingId, string $reason): FinanceConsolidationAccountMapping
    {
        return $this->review($tenantId, $actorId, $mappingId, $reason, FinanceConsolidationAccountMapping::APPROVED);
    }

    public function reject(int $tenantId, int $actorId, int $mappingId, string $reason): FinanceConsolidationAccountMapping
    {
        return $this->review($tenantId, $actorId, $mappingId, $reason, FinanceConsolidationAccountMapping::REJECTED);
    }

    private function review(int $tenantId, int $actorId, int $mappingId, string $reason, string $decision): FinanceConsolidationAccountMapping
    {
        $snapshot = FinanceConsolidationAccountMapping::query()->where('tenant_id', $tenantId)->findOrFail($mappingId);

        return DB::transaction(function () use ($tenantId, $actorId, $mappingId, $reason, $decision, $snapshot): FinanceConsolidationAccountMapping {
            // Keep lock order identical to propose(): account, then proposal.
            LedgerAccount::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $snapshot->legal_entity_id)
                ->lockForUpdate()->findOrFail($snapshot->ledger_account_id);

            $mapping = FinanceConsolidationAccountMapping::query()
                ->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($mappingId);
            if ($mapping->status !== FinanceConsolidationAccountMapping::PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending consolidation mappings can be reviewed.']);
            }
            if ((int) $mapping->created_by === $actorId) {
                throw ValidationException::withMessages(['reviewer' => 'The proposer cannot approve or reject their own mapping.']);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['review_reason' => 'A review reason is required.']);
            }

            $account = LedgerAccount::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $mapping->legal_entity_id)->findOrFail($mapping->ledger_account_id);
            if ((string) $account->account_type !== (string) $mapping->account_type) {
                throw ValidationException::withMessages(['account_type' => 'The ledger account type changed after proposal; submit a new mapping for review.']);
            }
            if ($decision === FinanceConsolidationAccountMapping::APPROVED
                && FinanceConsolidationAccountMapping::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $mapping->legal_entity_id)
                    ->where('ledger_account_id', $mapping->ledger_account_id)
                    ->where('status', FinanceConsolidationAccountMapping::APPROVED)
                    ->where('id', '<>', $mapping->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Another approved mapping exists for this account; this proposal cannot be approved.']);
            }

            $mapping->status = $decision;
            $mapping->reviewed_by = $actorId;
            $mapping->reviewed_at = now();
            $mapping->review_reason = trim($reason);
            $mapping->save();
            return $mapping->fresh();
        }, 3);
    }
}
