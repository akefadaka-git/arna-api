<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;

final class GovernanceElectionManualCountService
{
    /**
     * Record aggregate physical count evidence. The batch remains pending until
     * a second, independently authenticated committee member attests it.
     *
     * @param array{batch_reference:string,polling_station?:?string,ballots_received:int,ballots_valid:int,ballots_rejected:int,sealed_box_reference:string,count_sheet_reference:string,lines:array<int,array{candidate_nomination_id:int,ballot_count:int,share_vote_weight:string|int|float}>} $data
     * @return array{batch:object,lines:array<int,object>}
     */
    public function record(int $tenantId, int $electionId, int $actorId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $electionId, $actorId, $data): array {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);

            if (!$election->isAuthorityVerified() || $election->status !== 'counting'
                || $election->voting_method !== 'physical') {
                throw ValidationException::withMessages(['election' => 'Manual physical count evidence may be recorded only for an authority-verified physical election in counting status.']);
            }

            if ($actorId < 1) {
                throw new AuthorizationException('An authenticated count recorder is required.');
            }

            $recorder = $this->activeCountMember($tenantId, $electionId, $actorId, true);
            if ($recorder === null) {
                throw new AuthorizationException('The count recorder must be an active, non-recused committee or supervisory member.');
            }

            $received = (int) $data['ballots_received'];
            $valid = (int) $data['ballots_valid'];
            $rejected = (int) $data['ballots_rejected'];
            if ($received < 0 || $valid < 0 || $rejected < 0 || $valid + $rejected !== $received) {
                throw ValidationException::withMessages(['ballots_received' => 'Received ballots must equal valid plus rejected ballots for a completed count batch.']);
            }

            $reference = trim((string) $data['batch_reference']);
            $sealedBox = trim((string) $data['sealed_box_reference']);
            $countSheet = trim((string) $data['count_sheet_reference']);
            if ($reference === '' || $sealedBox === '' || $countSheet === '') {
                throw ValidationException::withMessages(['batch_reference' => 'Batch, sealed-box and count-sheet evidence references are required.']);
            }

            $lines = $data['lines'] ?? [];
            if (!is_array($lines) || $lines === []) {
                throw ValidationException::withMessages(['lines' => 'At least one candidate count line is required.']);
            }

            $seen = [];
            foreach ($lines as $line) {
                $nominationId = (int) ($line['candidate_nomination_id'] ?? 0);
                $ballotCount = (int) ($line['ballot_count'] ?? -1);
                $weight = (string) ($line['share_vote_weight'] ?? '');
                if ($nominationId < 1 || $ballotCount < 0
                    || !preg_match('/^\d+(?:\.\d{1,4})?$/', $weight)) {
                    throw ValidationException::withMessages(['lines' => 'Each candidate line requires a candidate, non-negative ballot count and non-negative four-decimal share-vote weight.']);
                }
                if (isset($seen[$nominationId])) {
                    throw ValidationException::withMessages(['lines' => 'A candidate may appear only once in a count batch.']);
                }
                $seen[$nominationId] = true;

                $candidate = DB::table('governance_election_nominations')
                    ->where('tenant_id', $tenantId)
                    ->where('election_id', $electionId)
                    ->where('id', $nominationId)
                    ->where('final_ballot_approved', true)
                    ->first(['id']);
                if ($candidate === null) {
                    throw ValidationException::withMessages(['lines' => 'Every count line must reference a final-ballot-approved candidate in this election.']);
                }
            }

            $now = Carbon::now();
            $batchId = DB::table('governance_election_manual_count_batches')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'batch_reference' => $reference,
                'polling_station' => isset($data['polling_station']) ? trim((string) $data['polling_station']) : null,
                'ballots_received' => $received,
                'ballots_valid' => $valid,
                'ballots_rejected' => $rejected,
                'sealed_box_reference' => $sealedBox,
                'count_sheet_reference' => $countSheet,
                'entered_by' => $actorId,
                'witnessed_by' => null,
                'witnessed_at' => null,
                'status' => 'pending_witness',
                'counted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $lineRows = array_map(static fn (array $line): array => [
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'batch_id' => $batchId,
                'candidate_nomination_id' => (int) $line['candidate_nomination_id'],
                'line_type' => 'candidate',
                'ballot_count' => (int) $line['ballot_count'],
                'share_vote_weight' => bcadd((string) $line['share_vote_weight'], '0', 4),
                'created_at' => $now,
                'updated_at' => $now,
            ], $lines);

            DB::table('governance_election_manual_count_lines')->insert($lineRows);

            return [
                'batch' => DB::table('governance_election_manual_count_batches')->where('id', $batchId)->first(),
                'lines' => DB::table('governance_election_manual_count_lines')->where('batch_id', $batchId)->orderBy('id')->get()->all(),
            ];
        });
    }

    /** A separate authenticated actor attests the pending count batch. */
    public function witness(int $tenantId, int $electionId, int $batchId, int $witnessUserId): object
    {
        return DB::transaction(function () use ($tenantId, $electionId, $batchId, $witnessUserId): object {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);

            if (!$election->isAuthorityVerified() || $election->status !== 'counting'
                || $election->voting_method !== 'physical') {
                throw ValidationException::withMessages(['election' => 'Witness attestation is available only while an authority-verified physical election is in counting status.']);
            }

            $batch = DB::table('governance_election_manual_count_batches')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->lockForUpdate()
                ->find($batchId);

            if ($batch === null || $batch->status !== 'pending_witness' || $batch->witnessed_by !== null) {
                throw ValidationException::withMessages(['batch' => 'Only a pending, unattested count batch may be witnessed.']);
            }

            $recorder = $this->activeCountMember($tenantId, $electionId, (int) $batch->entered_by, true);
            $witness = $this->activeCountMember($tenantId, $electionId, $witnessUserId, true);
            if ($recorder === null || $witness === null) {
                throw new AuthorizationException('The recorder and witness must remain active, non-recused committee or supervisory members.');
            }
            if ((int) $batch->entered_by === $witnessUserId
                || ($recorder->person_id !== null && $witness->person_id !== null
                    && (int) $recorder->person_id === (int) $witness->person_id)) {
                throw ValidationException::withMessages(['batch' => 'The witness must be a different person from the count recorder.']);
            }

            $now = Carbon::now();
            DB::table('governance_election_manual_count_batches')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('id', $batchId)
                ->where('status', 'pending_witness')
                ->update([
                    'witnessed_by' => $witnessUserId,
                    'witnessed_at' => $now,
                    'status' => 'witnessed',
                    'updated_at' => $now,
                ]);

            return DB::table('governance_election_manual_count_batches')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('id', $batchId)
                ->first();
        });
    }

    /**
     * @return array{batches:array<int,object>,lines:array<int,object>}
     */
    public function listForElection(int $tenantId, int $electionId, int $actorId): array
    {
        if ($this->activeCountMember($tenantId, $electionId, $actorId) === null) {
            throw new AuthorizationException('Active, non-recused count committee membership is required.');
        }

        $batches = DB::table('governance_election_manual_count_batches')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->orderBy('counted_at')
            ->orderBy('id')
            ->get();

        $lines = DB::table('governance_election_manual_count_lines')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->orderBy('batch_id')
            ->orderBy('id')
            ->get();

        return ['batches' => $batches->all(), 'lines' => $lines->all()];
    }

    private function activeCountMember(int $tenantId, int $electionId, int $userId, bool $lock = false): ?object
    {
        if ($userId < 1) {
            return null;
        }

        $query = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('recused_at')
            ->whereIn('member_role', ['committee_member', 'supervisory_member']);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first(['id', 'person_id', 'user_id']);
    }
}
