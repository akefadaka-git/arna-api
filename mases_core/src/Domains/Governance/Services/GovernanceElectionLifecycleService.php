<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceElectionLifecycleChanged;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use RuntimeException;

/**
 * Internal lifecycle operations. Callers must enforce the approved Governance
 * permission and retain the signed authority instrument outside this service.
 */
final class GovernanceElectionLifecycleService
{
    public function createPlanned(int $tenantId, string $code, string $title, ?int $bodyId = null, ?string $recordDate = null): GovernanceElection
    {
        if (trim($code) === '' || trim($title) === '') {
            throw ValidationException::withMessages(['election' => 'Election code and title are required.']);
        }
        $election = GovernanceElection::query()->create([
            'tenant_id' => $tenantId,
            'governance_body_id' => $bodyId,
            'code' => $code,
            'title' => $title,
            'status' => 'planned',
            'record_date' => $recordDate,
            'voting_method' => 'physical',
        ]);
        $this->auditAfterCommit($election, 'created', null, Auth::id());
        return $election;
    }

    public function verifyAuthority(int $tenantId, int $electionId, string $instrumentReference, int $verifierId): GovernanceElection
    {
        if (trim($instrumentReference) === '' || $verifierId < 1) {
            throw ValidationException::withMessages(['authority_reference' => 'A signed authority instrument and verifier are required.']);
        }
        return DB::transaction(function () use ($tenantId, $electionId, $instrumentReference, $verifierId): GovernanceElection {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if ($election->status !== 'planned') {
                throw new RuntimeException('Authority may only be verified before nominations open.');
            }
            $before = $election->only(['status', 'authority_reference', 'authority_verified_at', 'authority_verified_by', 'voting_rule_version', 'nominations_open_at', 'nominations_close_at', 'poll_open_at', 'poll_close_at']);
            $election->forceFill([
                'authority_reference' => $instrumentReference,
                'authority_verified_at' => Carbon::now(),
                'authority_verified_by' => $verifierId,
            ])->save();
            $this->auditAfterCommit($election, 'authority_verified', $before, $verifierId);
            return $election;
        });
    }

    public function openNominations(int $tenantId, int $electionId, string $ruleVersion, string $opensAt, string $closesAt): GovernanceElection
    {
        return DB::transaction(function () use ($tenantId, $electionId, $ruleVersion, $opensAt, $closesAt): GovernanceElection {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'planned') {
                throw new RuntimeException('Verified authority and planned status are required.');
            }
            if (trim($ruleVersion) === '' || Carbon::parse($opensAt)->gte(Carbon::parse($closesAt))) {
                throw ValidationException::withMessages(['schedule' => 'An approved rule version and valid nomination window are required.']);
            }
            $before = $election->only(['status', 'authority_reference', 'authority_verified_at', 'authority_verified_by', 'voting_rule_version', 'nominations_open_at', 'nominations_close_at', 'poll_open_at', 'poll_close_at']);
            $election->forceFill([
                'status' => 'nominating',
                'voting_rule_version' => $ruleVersion,
                'nominations_open_at' => $opensAt,
                'nominations_close_at' => $closesAt,
            ])->save();
            $this->auditAfterCommit($election, 'nominations_opened', $before, Auth::id());
            return $election;
        });
    }

    public function openPhysicalVoting(int $tenantId, int $electionId, string $opensAt, string $closesAt): GovernanceElection
    {
        return DB::transaction(function () use ($tenantId, $electionId, $opensAt, $closesAt): GovernanceElection {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'nominating') {
                throw new RuntimeException('Verified authority and completed nominations are required before voting.');
            }
            if ($election->voting_rule_version === null || Carbon::parse($opensAt)->gte(Carbon::parse($closesAt))) {
                throw ValidationException::withMessages(['schedule' => 'A rule version and valid voting window are required.']);
            }
            $snapshotExists = DB::table('governance_election_register_snapshots')
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->exists();
            if (!$snapshotExists) {
                throw new RuntimeException('A frozen, approved voter register is required before voting.');
            }
            $before = $election->only(['status', 'authority_reference', 'authority_verified_at', 'authority_verified_by', 'voting_rule_version', 'nominations_open_at', 'nominations_close_at', 'poll_open_at', 'poll_close_at']);
            $election->forceFill([
                'status' => 'voting',
                'voting_method' => 'physical',
                'poll_open_at' => $opensAt,
                'poll_close_at' => $closesAt,
            ])->save();
            $this->auditAfterCommit($election, 'physical_voting_opened', $before, Auth::id());
            return $election;
        });
    }

    public function closeVoting(int $tenantId, int $electionId): GovernanceElection
    {
        return DB::transaction(function () use ($tenantId, $electionId): GovernanceElection {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'voting') {
                throw new RuntimeException('Only an authorized active election can close voting.');
            }
            $before = $election->only(['status', 'authority_reference', 'authority_verified_at', 'authority_verified_by', 'voting_rule_version', 'nominations_open_at', 'nominations_close_at', 'poll_open_at', 'poll_close_at']);
            $election->forceFill(['status' => 'counting'])->save();
            $this->auditAfterCommit($election, 'voting_closed', $before, Auth::id());
            return $election;
        });
    }

    /** Dispatch only after the enclosing transaction commits. */
    private function auditAfterCommit(GovernanceElection $election, string $action, ?array $before, ?int $actorId): void
    {
        DB::afterCommit(static function () use ($election, $action, $before, $actorId): void {
            event(new GovernanceElectionLifecycleChanged($election, $action, $before, $actorId));
        });
    }
}
