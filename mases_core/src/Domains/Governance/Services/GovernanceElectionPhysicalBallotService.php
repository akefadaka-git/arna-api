<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Governance\\Services;

use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;
use MaaleArna\\MASES\\Core\\Domains\\Governance\\Models\\GovernanceElection;
use MaaleArna\\MASES\\Core\\Domains\\Governance\\Models\\GovernanceElectionBallot;
use RuntimeException;

final class GovernanceElectionPhysicalBallotService
{
    public function __construct(
        private readonly GovernanceElectionRegisterService $registers,
    ) {
    }

    public function issue(int $tenantId, int $electionId, int $voterId, string $ballotReference, int $actorId): GovernanceElectionBallot
    {
        return DB::transaction(function () use ($tenantId, $electionId, $voterId, $ballotReference, $actorId): GovernanceElectionBallot {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'voting' || $election->voting_method !== 'physical') {
                throw new RuntimeException('Physical ballot issuance is not enabled for this election state and method.');
            }
            if (trim($ballotReference) === '') {
                throw ValidationException::withMessages(['ballot_reference' => 'A controlled ballot reference is required.']);
            }
            if ($actorId < 1) {
                throw ValidationException::withMessages(['actor_id' => 'An authenticated ballot-issuing actor is required.']);
            }

            $this->assertFrozenRegisterVoter($tenantId, $electionId, $voterId);

            return GovernanceElectionBallot::query()->create([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'voter_id' => $voterId,
                'ballot_reference' => trim($ballotReference),
                'channel' => 'physical',
                'status' => 'issued',
                'issued_at' => Carbon::now(),
                'issued_by' => $actorId,
            ]);
        });
    }

    public function receive(int $tenantId, int $electionId, int $ballotId, string $custodyReference): GovernanceElectionBallot
    {
        return DB::transaction(function () use ($tenantId, $electionId, $ballotId, $custodyReference): GovernanceElectionBallot {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'voting' || $election->voting_method !== 'physical') {
                throw new RuntimeException('Physical ballot receipt is not permitted for this election state and method.');
            }
            if (trim($custodyReference) === '') {
                throw ValidationException::withMessages(['custody_reference' => 'A custody evidence reference is required.']);
            }

            $ballot = GovernanceElectionBallot::query()
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->lockForUpdate()
                ->findOrFail($ballotId);

            if ($ballot->channel !== 'physical' || $ballot->status !== 'issued') {
                throw new RuntimeException('Only an issued physical ballot may be received.');
            }

            // Revalidate the same tenant/election voter against the approved frozen
            // register at receipt time; the custody record is not a vote record.
            $this->assertFrozenRegisterVoter($tenantId, $electionId, (int) $ballot->voter_id);

            $ballot->forceFill([
                'status' => 'received',
                'custody_reference' => trim($custodyReference),
                'received_at' => Carbon::now(),
            ])->save();

            return $ballot;
        });
    }

    private function assertFrozenRegisterVoter(int $tenantId, int $electionId, int $voterId): object
    {
        // Read the voter-to-snapshot reference first without taking a row lock.
        // Then follow the global election -> snapshot -> voter lock order used
        // by register reconciliation and approval.
        $reference = DB::table('governance_election_voters')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('id', $voterId)
            ->first(['snapshot_id']);

        if ($reference === null) {
            throw ValidationException::withMessages(['voter_id' => 'The voter is not in this election register.']);
        }

        $snapshot = DB::table('governance_election_register_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('id', $reference->snapshot_id)
            ->lockForUpdate()
            ->first();

        if ($snapshot === null || $snapshot->register_status !== 'frozen'
            || $snapshot->frozen_at === null
            || $snapshot->prepared_by === null
            || $snapshot->reconciled_by === null
            || $snapshot->approved_by === null
            || count(array_unique([
                (int) $snapshot->prepared_by,
                (int) $snapshot->reconciled_by,
                (int) $snapshot->approved_by,
            ])) !== 3
            || $snapshot->reconciled_at === null
            || $snapshot->approved_at === null
            || $snapshot->integrity_verified_at === null
            || (int) $snapshot->voter_count < 1) {
            throw new RuntimeException('An independently approved, integrity-verified frozen voter register is required for physical ballot custody.');
        }

        if (!$this->registers->hasValidSnapshotIntegrity($tenantId, $electionId, (int) $snapshot->id, true)) {
            throw new RuntimeException('The frozen voter register failed integrity verification; physical ballot custody is blocked.');
        }

        // Re-read after locking the snapshot so a concurrent row move cannot
        // make the initial non-locking reference stale.
        $voter = DB::table('governance_election_voters')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('snapshot_id', $snapshot->id)
            ->where('id', $voterId)
            ->lockForUpdate()
            ->first();

        if ($voter === null) {
            throw ValidationException::withMessages(['voter_id' => 'The voter is not in the approved frozen election register.']);
        }

        return $voter;
    }
}
