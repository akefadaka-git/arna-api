<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Listeners;

use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceElectionLifecycleChanged;
use Throwable;

final class AuditGovernanceElectionLifecycleEvent
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function handle(GovernanceElectionLifecycleChanged $event): void
    {
        try {
            $election = $event->election;
            $transition = $event->transition;

            $this->auditService->log(
                action: 'governance.election.lifecycle_transitioned',
                data: [
                    'source' => 'domain-event',
                    'event' => GovernanceElectionLifecycleChanged::class,
                    'transition_id' => (int) $transition->id,
                    'sequence' => (int) $transition->sequence,
                    'aggregate_version' => (int) $transition->aggregate_version,
                    'action' => $transition->action,
                ],
                user: (int) $transition->actor_user_id,
                tenantId: (int) $transition->tenant_id,
                resourceType: 'GovernanceElection',
                resourceId: (string) $election->id,
                oldValues: ['status' => $transition->from_status],
                newValues: [
                    'status' => $transition->to_status,
                    'action' => $transition->action,
                    'reason' => $transition->reason,
                    'evidence_references' => $transition->evidence_references,
                    'aggregate_version' => (int) $transition->aggregate_version,
                ],
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
                requestId: (string) request()->header('X-Request-ID', request()->header('X-Correlation-ID', '')),
            );
        } catch (Throwable $exception) {
            // The lifecycle transition is already committed and independently
            // retained in the transition ledger; audit failure must not undo it.
            Log::error('Election lifecycle audit listener failed', [
                'election_id' => $event->election->id,
                'transition_id' => $event->transition->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
