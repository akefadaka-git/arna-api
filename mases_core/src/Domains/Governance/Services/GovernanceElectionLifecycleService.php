<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionLifecycleTransition;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceElectionLifecycleChanged;

final class GovernanceElectionLifecycleService
{
    public function list(int $tenantId, int $perPage = 25)
    {
        return GovernanceElection::query()->forTenant($tenantId)->orderByDesc('id')->paginate(min(max($perPage, 1), 100));
    }

    public function get(int $tenantId, int $electionId): GovernanceElection
    {
        return GovernanceElection::query()->forTenant($tenantId)->findOrFail($electionId);
    }

    public function create(int $tenantId, User $actor, array $data): GovernanceElection
    {
        $this->authorize($actor, $tenantId, 'governance.election.manage');
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '' || mb_strlen($code) > 64) {
            throw ValidationException::withMessages(['code' => 'A non-empty election code of at most 64 characters is required.']);
        }
        if (GovernanceElection::query()->forTenant($tenantId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'The election code already exists in this tenant.']);
        }
        $bodyId = isset($data['governance_body_id']) ? (int) $data['governance_body_id'] : null;
        if ($bodyId !== null && !DB::table('governance_bodies')->where('tenant_id', $tenantId)->where('id', $bodyId)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['governance_body_id' => 'The governance body must exist in the same tenant.']);
        }

        $election = GovernanceElection::query()->create([
            'tenant_id' => $tenantId,
            'governance_body_id' => $bodyId,
            'code' => $code,
            'title_am' => $data['title_am'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'election_scope' => $data['election_scope'],
            'election_type' => $data['election_type'],
            'timezone' => $data['timezone'] ?? 'Africa/Addis_Ababa',
            'status' => 'draft',
        ]);

        return $election->refresh();
    }

    public function transition(
        int $tenantId,
        int $electionId,
        User $actor,
        string $action,
        int $expectedVersion,
        ?string $reason = null,
        array $evidenceReferences = [],
        array $authority = [],
        array $schedule = [],
    ): GovernanceElection {
        $permission = match ($action) {
            'authorize' => 'governance.election.authorize',
            'schedule' => 'governance.election.schedule',
            'suspend', 'resume' => 'governance.election.suspend',
            'cancel' => 'governance.election.cancel',
            'archive' => 'governance.election.archive',
            default => throw ValidationException::withMessages(['action' => 'Unsupported lifecycle action.']),
        };
        $this->authorize($actor, $tenantId, $permission);

        return DB::transaction(function () use ($tenantId, $electionId, $actor, $action, $expectedVersion, $reason, $evidenceReferences, $authority, $schedule): GovernanceElection {
            $election = GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->lockForUpdate()->first();
            if (!$election) {
                throw (new ModelNotFoundException())->setModel(GovernanceElection::class, [$electionId]);
            }
            if ((int) $election->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'The election was changed by another request. Reload and retry with the current version.']);
            }

            $from = $election->status;
            $to = match ($action) {
                'authorize' => $this->authorizeElection($election, $authority),
                'schedule' => $this->scheduleElection($election, $schedule),
                'suspend' => $this->suspendElection($election, $reason),
                'resume' => $this->resumeElection($election),
                'cancel' => $this->cancelElection($election, $reason),
                'archive' => $this->archiveElection($election),
                default => throw ValidationException::withMessages(['action' => 'Unsupported lifecycle action.']),
            };

            if ($action === 'authorize') {
                $election->authorized_by = (int) $actor->getAuthIdentifier();
            } elseif ($action === 'suspend') {
                $election->suspended_by = (int) $actor->getAuthIdentifier();
            } elseif ($action === 'cancel') {
                $election->cancelled_by = (int) $actor->getAuthIdentifier();
            } elseif ($action === 'archive') {
                $election->archived_by = (int) $actor->getAuthIdentifier();
            }
            $election->status = $to;
            $election->version = $expectedVersion + 1;
            $election->save();

            $sequence = (int) GovernanceElectionLifecycleTransition::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->max('sequence') + 1;

            $transition = GovernanceElectionLifecycleTransition::query()->create([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'sequence' => $sequence,
                'action' => $action,
                'from_status' => $from,
                'to_status' => $to,
                'actor_user_id' => (int) $actor->getAuthIdentifier(),
                'reason' => $reason,
                'evidence_references' => $evidenceReferences,
                'aggregate_version' => $election->version,
                'occurred_at' => now(),
            ]);

            $election->refresh();
            DB::afterCommit(static fn () => event(new GovernanceElectionLifecycleChanged($election, $transition)));

            return $election;
        }, 3);
    }

    private function authorizeElection(GovernanceElection $election, array $authority): string
    {
        if ($election->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft election can be authorized.']);
        }
        foreach (['authority_reference', 'authority_evidence_digest', 'authority_version', 'rule_set_version', 'rule_set_digest'] as $key) {
            if (trim((string) ($authority[$key] ?? '')) === '') {
                throw ValidationException::withMessages([$key => 'Authoritative source and versioned rule evidence are required before authorization.']);
            }
        }
        foreach (['authority_evidence_digest', 'rule_set_digest'] as $key) {
            if (!preg_match('/^[a-f0-9]{64}$/i', (string) $authority[$key])) {
                throw ValidationException::withMessages([$key => 'A SHA-256 digest (64 hexadecimal characters) is required.']);
            }
        }
        $election->fill([
            'authority_reference' => trim($authority['authority_reference']),
            'authority_document_id' => $authority['authority_document_id'] ?? null,
            'authority_evidence_digest' => strtolower($authority['authority_evidence_digest']),
            'authority_version' => trim($authority['authority_version']),
            'authority_snapshot' => $authority['authority_snapshot'] ?? null,
            'rule_set_version' => trim($authority['rule_set_version']),
            'rule_set_digest' => strtolower($authority['rule_set_digest']),
            'authorized_at' => now(),
        ]);
        return 'authorized';
    }

    private function scheduleElection(GovernanceElection $election, array $schedule): string
    {
        if ($election->status !== 'authorized') {
            throw ValidationException::withMessages(['status' => 'Only an authorized election can be scheduled.']);
        }
        if (!$election->authority_reference || !$election->authority_evidence_digest || !$election->rule_set_digest) {
            throw ValidationException::withMessages(['authority' => 'Verified authority and rule evidence are required.']);
        }
        $start = $schedule['scheduled_start_at'] ?? null;
        $end = $schedule['scheduled_end_at'] ?? null;
        if (!$start || !$end || strtotime($start) === false || strtotime($end) === false || strtotime($start) >= strtotime($end)) {
            throw ValidationException::withMessages(['scheduled_start_at' => 'A valid start time earlier than the end time is required.']);
        }
        $election->scheduled_start_at = $start;
        $election->scheduled_end_at = $end;
        $election->timezone = $schedule['timezone'] ?? $election->timezone;
        return 'scheduled';
    }

    private function suspendElection(GovernanceElection $election, ?string $reason): string
    {
        if (!in_array($election->status, ['authorized', 'scheduled'], true) || trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required; only authorized or scheduled elections may be suspended in Module 1.']);
        }
        $election->suspended_at = now();
        $election->suspension_reason = $reason;
        return 'suspended';
    }

    private function resumeElection(GovernanceElection $election): string
    {
        if ($election->status !== 'suspended') {
            throw ValidationException::withMessages(['status' => 'Only a suspended election can be resumed.']);
        }
        $election->suspended_at = null;
        $election->suspended_by = null;
        $election->suspension_reason = null;
        return $election->scheduled_start_at ? 'scheduled' : 'authorized';
    }

    private function cancelElection(GovernanceElection $election, ?string $reason): string
    {
        if (!in_array($election->status, ['draft', 'authorized', 'scheduled', 'suspended'], true) || trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required; this election cannot be cancelled from its current state.']);
        }
        $election->cancelled_at = now();
        $election->cancellation_reason = $reason;
        return 'cancelled';
    }

    private function archiveElection(GovernanceElection $election): string
    {
        if (!in_array($election->status, ['cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a cancelled election can be archived in Module 1.']);
        }
        $election->archived_at = now();
        return 'archived';
    }

    private function authorize(User $actor, int $tenantId, string $permission): void
    {
        if (!function_exists('mases_tenant_id') || (int) mases_tenant_id() !== $tenantId) {
            throw new AuthorizationException('A resolved tenant context is required.');
        }
        if (!app(\MaaleArna\MASES\Core\Authorization\Services\AuthorizationService::class)->hasPermission($permission, $actor, $tenantId)) {
            throw new AuthorizationException('The required election permission is not granted in this tenant.');
        }
    }
}
