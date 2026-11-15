<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use RuntimeException;

final class GovernanceElectionManualCountService
{
    /**
     * Record an aggregate physical count batch. This persists evidence only:
     * it does not calculate winners, resolve disputed rules, or certify a tally.
     *
     * @param array{batch_reference:string,polling_station?:?string,ballots_received:int,ballots_valid:int,ballots_rejected:int,sealed_box_reference:string,count_sheet_reference:string,lines:array<int,array{candidate_nomination_id:int,ballot_count:int,share_vote_weight:string|int|float}>} $data
     * @return array{batch:object,lines:array<int,object>}
     */
    public function record(int $tenantId, int $electionId, int $actorId, int $witnessUserId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $electionId, $actorId, $witnessUserId, $data): array {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);

            if (!$election->isAuthorityVerified() || $election->status !== 'counting'
                || $election->voting_method !== 'physical') {
                throw new RuntimeException('Manual physical count evidence may be recorded only for an authority-verified physical election in counting status.');
            }

            if ($actorId < 1 || $witnessUserId < 1 || $actorId === $witnessUserId) {
                throw ValidationException::withMessages(['witnessed_by' => 'The count recorder and independent witness must be two distinct users.']);
            }

            $members = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('status', 'active')
                ->whereNull('recused_at')
                ->whereIn('member_role', ['committee_member', 'supervisory_member']);

            $recorder = (clone $members)->where('user_id', $actorId)->first(['id', 'person_id', 'user_id']);
            $witness = (clone $members)->where('user_id', $witnessUserId)->first(['id', 'person_id', 'user_id']);

            if ($recorder === null || $witness === null) {
                throw ValidationException::withMessages(['witnessed_by' => 'The recorder and witness must both be active, non-recused committee or supervisory members of this election.']);
            }
            if ((int) $recorder->id === (int) $witness->id
                || ($recorder->person_id !== null && $witness->person_id !== null
                    && (int) $recorder->person_id === (int) $witness->person_id)) {
                throw ValidationException::withMessages(['witnessed_by' => 'The count recorder and witness must be independent persons.']);
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
                'witnessed_by' => $witnessUserId,
                'counted_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $now = Carbon::now();
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

    /**
     * @return array{batches:array<int,object>,lines:array<int,object>}
     */
    public function listForElection(int $tenantId, int $electionId, int $actorId): array
    {
        $member = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('user_id', $actorId)
            ->where('status', 'active')
            ->whereNull('recused_at')
            ->whereIn('member_role', ['committee_member', 'supervisory_member'])
            ->exists();

        if (!$member) {
            throw new RuntimeException('Active, non-recused count committee membership is required.');
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
}
