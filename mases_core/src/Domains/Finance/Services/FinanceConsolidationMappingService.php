<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Services;

use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\FinanceConsolidationAccountMapping;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LedgerAccount;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LegalEntity;

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

        try {
            return DB::transaction(function () use ($tenantId, $actorId, $legalEntityId, $ledgerAccountId, $code, $reason): FinanceConsolidationAccountMapping {
                $entity = LegalEntity::query()->where('tenant_id', $tenantId)->where('status', 'active')
                    ->lockForUpdate()->findOrFail($legalEntityId);
                // Serialize proposals for this account before allocating a version.
                $account = LedgerAccount::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entity->id)->lockForUpdate()->findOrFail($ledgerAccountId);

                $existing = FinanceConsolidationAccountMapping::query()
                    ->where('tenant_id', $tenantId)->where('legal_entity_id', $entity->id)
                    ->where('ledger_account_id', $account->id);
                if ((clone $existing)->where('status', FinanceConsolidationAccountMapping::APPROVED)->exists()) {
                    throw ValidationException::withMessages(['ledger_account_id' => 'An approved mapping already exists. A separately reviewed remapping workflow is required to change it.']);
                }
                if ((clone $existing)->where('status', FinanceConsolidationAccountMapping::PENDING)->exists()) {
                    throw ValidationException::withMessages(['ledger_account_id' => 'A pending mapping already exists for this account; review it before proposing another.']);
                }

                $version = ((int) (clone $existing)->max('proposal_version')) + 1;
                return FinanceConsolidationAccountMapping::query()->create([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $entity->id,
                    'ledger_account_id' => $account->id,
                    'consolidation_account_code' => $code,
                    'account_type' => (string) $account->account_type,
                    'status' => FinanceConsolidationAccountMapping::PENDING,
                    'proposal_version' => $version,
                    'created_by' => $actorId,
                    'mapping_reason' => $reason,
                ]);
            });
        } catch (QueryException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'ledger_account_id' => 'A competing mapping proposal was created concurrently. Reload the account and review its current mapping state.',
            ]);
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
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['review_reason' => 'A review reason is required.']);
        }

        return DB::transaction(function () use ($tenantId, $actorId, $mappingId, $reason, $decision): FinanceConsolidationAccountMapping {
            $candidate = FinanceConsolidationAccountMapping::query()
                ->where('tenant_id', $tenantId)->findOrFail($mappingId);

            // Lock in account-then-proposal order, matching propose(), to reduce deadlock risk.
            LedgerAccount::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $candidate->legal_entity_id)
                ->lockForUpdate()->findOrFail($candidate->ledger_account_id);

            $mapping = FinanceConsolidationAccountMapping::query()
                ->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($mappingId);
            if ($mapping->status !== FinanceConsolidationAccountMapping::PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending consolidation mappings can be reviewed.']);
            }
            if ((int) $mapping->created_by === $actorId) {
                throw ValidationException::withMessages(['reviewer' => 'The proposer cannot approve or reject their own mapping.']);
            }

            $account = LedgerAccount::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $mapping->legal_entity_id)
                ->findOrFail($mapping->ledger_account_id);
            if ((string) $account->account_type !== (string) $mapping->account_type) {
                throw ValidationException::withMessages(['account_type' => 'The ledger account type changed after proposal; submit a new mapping for review.']);
            }
            if ($decision === FinanceConsolidationAccountMapping::APPROVED
                && FinanceConsolidationAccountMapping::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $mapping->legal_entity_id)
                    ->where('ledger_account_id', $mapping->ledger_account_id)
                    ->where('status', FinanceConsolidationAccountMapping::APPROVED)
                    ->where('id', '<>', $mapping->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Another mapping is already approved for this account. A governed remapping process is required.']);
            }

            $mapping->status = $decision;
            $mapping->reviewed_by = $actorId;
            $mapping->reviewed_at = now();
            $mapping->review_reason = $reason;
            $mapping->save();
            return $mapping->fresh();
        });
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());
        return $sqlState === '23505' || $driverCode === 1062
            || ($driverCode === 19 && str_contains($message, 'unique constraint'));
    }
}
