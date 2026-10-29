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
        $account = LedgerAccount::query()->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entity->id)->findOrFail($ledgerAccountId);

        if (FinanceConsolidationAccountMapping::query()->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entity->id)->where('ledger_account_id', $account->id)
            ->where('status', FinanceConsolidationAccountMapping::APPROVED)->exists()) {
            throw ValidationException::withMessages(['ledger_account_id' => 'An approved mapping already exists. A separately reviewed remapping workflow is required to change it.']);
        }

        try {
            return FinanceConsolidationAccountMapping::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $entity->id,
                'ledger_account_id' => $account->id,
                'consolidation_account_code' => $code,
                'account_type' => (string) $account->account_type,
                'status' => FinanceConsolidationAccountMapping::PENDING,
                'created_by' => $actorId,
                'mapping_reason' => $reason,
            ]);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if (!in_array($sqlState, ['23000', '23505'], true) && !in_array($driverCode, [19, 1062], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'ledger_account_id' => 'A mapping record already exists for this entity account. Review it; do not create a competing mapping.',
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
        return DB::transaction(function () use ($tenantId, $actorId, $mappingId, $reason, $decision): FinanceConsolidationAccountMapping {
            $mapping = FinanceConsolidationAccountMapping::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($mappingId);
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
                ->where('legal_entity_id', $mapping->legal_entity_id)
                ->findOrFail($mapping->ledger_account_id);
            if ((string) $account->account_type !== (string) $mapping->account_type) {
                throw ValidationException::withMessages(['account_type' => 'The ledger account type changed after proposal; submit a new mapping for review.']);
            }

            $mapping->status = $decision;
            $mapping->reviewed_by = $actorId;
            $mapping->reviewed_at = now();
            $mapping->review_reason = trim($reason);
            $mapping->save();
            return $mapping->fresh();
        });
    }
}
