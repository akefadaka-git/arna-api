<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\FinanceIntelligenceProviderContract;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceAiProposal;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FinanceIntelligenceService
{
    private const TASK_TYPES = ['variance_review', 'cash_flow_review', 'ledger_anomaly_review', 'period_close_review'];

    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly FinanceIntelligenceProviderContract $provider,
    ) {
    }

    /** Read-only evidence retrieval; no mutating accounting service is invoked. */
    public function evidenceSnapshot(int $tenantId, int $legalEntityId, string $asOf): array
    {
        $entity = LegalEntity::query()->where('tenant_id', $tenantId)->findOrFail($legalEntityId);
        $report = $this->reports->trialBalance($tenantId, $legalEntityId, $asOf);
        $normalized = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $canonical = json_encode([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'entity_code' => (string) $entity->code,
            'as_of' => $asOf,
            'report' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return [
            'scope' => ['tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId, 'entity_code' => (string) $entity->code],
            'as_of' => $asOf,
            'report' => $normalized,
            'evidence_refs' => [[
                'type' => 'finance.trial_balance',
                'legal_entity_id' => $legalEntityId,
                'as_of' => $asOf,
                'sha256' => hash('sha256', $canonical),
            ]],
            'evidence_sha256' => hash('sha256', $canonical),
            'limitations' => ['Read-only trial-balance evidence only.', 'No source documents or external bank evidence are included.'],
        ];
    }

    public function generateProposal(int $tenantId, int $legalEntityId, int $actorId, string $taskType, string $asOf, string $question): FinanceAiProposal
    {
        if (!in_array($taskType, self::TASK_TYPES, true)) {
            throw ValidationException::withMessages(['task_type' => 'Unsupported Finance intelligence task.']);
        }

        $evidence = $this->evidenceSnapshot($tenantId, $legalEntityId, $asOf);
        $generated = $this->provider->generateProposal([
            'task_type' => $taskType,
            'question' => $question,
            'as_of' => $asOf,
            'scope' => $evidence['scope'],
        ], $evidence);

        foreach (['agent_key', 'task_template_version', 'title', 'summary', 'proposal', 'limitations'] as $required) {
            if (!array_key_exists($required, $generated)) {
                throw new \UnexpectedValueException('Finance intelligence provider omitted required field: '.$required);
            }
        }
        if (!is_array($generated['proposal']) || !is_array($generated['limitations'])) {
            throw new \UnexpectedValueException('Finance intelligence provider returned an invalid proposal or limitations payload.');
        }
        $confidence = $generated['confidence'] ?? null;
        if ($confidence !== null && (!is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1)) {
            throw new \UnexpectedValueException('Finance intelligence confidence must be between 0 and 1.');
        }

        return FinanceAiProposal::query()->create([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'requested_by' => $actorId,
            'agent_key' => (string) $generated['agent_key'],
            'task_type' => $taskType,
            'task_template_version' => (string) $generated['task_template_version'],
            'model_provider' => $generated['model_provider'] ?? null,
            'model_name' => $generated['model_name'] ?? null,
            'model_version' => $generated['model_version'] ?? null,
            'status' => FinanceAiProposal::PROPOSED,
            'title' => (string) $generated['title'],
            'summary' => (string) $generated['summary'],
            'proposal' => $generated['proposal'],
            'evidence_refs' => $evidence['evidence_refs'],
            'evidence_sha256' => $evidence['evidence_sha256'],
            'confidence' => $confidence,
            'limitations' => array_values(array_unique(array_merge($evidence['limitations'], $generated['limitations']))),
        ]);
    }

    /** Approval records human review only; it never executes a financial operation. */
    public function approve(int $tenantId, int $proposalId, int $reviewerId, string $reason): FinanceAiProposal
    {
        return DB::transaction(function () use ($tenantId, $proposalId, $reviewerId, $reason): FinanceAiProposal {
            $proposal = FinanceAiProposal::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($proposalId);
            if ((int) $proposal->requested_by === $reviewerId) {
                throw new ConflictHttpException('A proposal creator cannot approve their own Finance AI proposal.');
            }
            if ($proposal->status !== FinanceAiProposal::PROPOSED) {
                throw new ConflictHttpException('Only proposed Finance AI items can be reviewed.');
            }
            $proposal->forceFill([
                'status' => FinanceAiProposal::APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ])->save();

            return $proposal->fresh();
        });
    }

    public function reject(int $tenantId, int $proposalId, int $reviewerId, string $reason): FinanceAiProposal
    {
        return DB::transaction(function () use ($tenantId, $proposalId, $reviewerId, $reason): FinanceAiProposal {
            $proposal = FinanceAiProposal::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($proposalId);
            if ($proposal->status !== FinanceAiProposal::PROPOSED) {
                throw new ConflictHttpException('Only proposed Finance AI items can be reviewed.');
            }
            $proposal->forceFill([
                'status' => FinanceAiProposal::REJECTED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ])->save();

            return $proposal->fresh();
        });
    }

    public function proposals(int $tenantId, int $legalEntityId)
    {
        return FinanceAiProposal::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->latest('id')
            ->paginate(25);
    }
}
