<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionBallot;
use RuntimeException;

final class GovernanceElectionPhysicalBallotService
{
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
            $voter = DB::table('governance_election_voters')->where('tenant_id', $tenantId)->where('election_id', $electionId)->where('id', $voterId)->lockForUpdate()->first();
            if ($voter === null) {
                throw ValidationException::withMessages(['voter_id' => 'The voter is not in this election register.']);
            }
            return GovernanceElectionBallot::query()->create([
                'tenant_id' => $tenantId, 'election_id' => $electionId, 'voter_id' => $voterId,
                'ballot_reference' => $ballotReference, 'channel' => 'physical', 'status' => 'issued',
                'issued_at' => Carbon::now(), 'issued_by' => $actorId,
            ]);
        });
    }

    public function receive(int $tenantId, int $electionId, int $ballotId, string $custodyReference): GovernanceElectionBallot
    {
        return DB::transaction(function () use ($tenantId, $electionId, $ballotId, $custodyReference): GovernanceElectionBallot {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'voting') {
                throw new RuntimeException('Ballot receipt is not permitted for this election state.');
            }
            if (trim($custodyReference) === '') {
                throw ValidationException::withMessages(['custody_reference' => 'A custody evidence reference is required.']);
            }
            $ballot = GovernanceElectionBallot::query()->where('tenant_id', $tenantId)->where('election_id', $electionId)->lockForUpdate()->findOrFail($ballotId);
            if ($ballot->channel !== 'physical' || $ballot->status !== 'issued') {
                throw new RuntimeException('Only an issued physical ballot may be received.');
            }
            $ballot->forceFill(['status' => 'received', 'custody_reference' => $custodyReference, 'received_at' => Carbon::now()])->save();
            return $ballot;
        });
    }
}
