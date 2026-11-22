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
     * Record aggregate physical-count evidence only. This service does not
     * calculate share-weighted results, rank candidates, or create a tally.
     *
     * @param array{batch_reference:string,polling_station?:?string,ballots_received:int,ballots_valid:int,ballots_rejected:int,sealed_box_reference:string,count_sheet_reference:string,lines:array<int,array{candidate_id:int,ballot_count:int}>} $data
     * @return array{batch:object,lines:array<int,object>}
     */
    public function record(int $tenantId, int $electionId, int $actorId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $electionId, $actorId, $data): array {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);
            $this->assertPhysicalCountingElection($election);

            if ($this->activeCountMember($tenantId, $electionId, $actorId, true) === null) {
                throw new AuthorizationException('An active, non-recused election committee or supervisory member is required to record a count batch.');
            }

            $received = (int) ($data['ballots_received'] ?? -1);
            $valid = (int) ($data['ballots_valid'] ?? -1);
            $rejected = (int) ($data['ballots_rejected'] ?? -1);
            if ($received < 0 || $valid < 0 || $rejected < 0 || $received !== $valid + $rejected) {
                throw ValidationException::withMessages([
                    'ballots_received' => 'Ballots received must equal valid ballots plus rejected ballots.',
                ]);
            }

            $lines = $data['lines'] ?? [];
            if (!is_array($lines) || $lines === []) {
                throw ValidationException::withMessages(['lines' => 'At least one candidate count line is required.']);
            }

            $normalized = [];
            foreach ($lines as $line) {
                $candidateId = (int) ($line['candidate_id'] ?? 0);
                $ballotCount = (int) ($line['ballot_count'] ?? -1);
                if ($candidateId < 1 || $ballotCount < 0 || $ballotCount > $valid) {
                    throw ValidationException::withMessages(['lines' => 'Each count line requires a valid candidate and non-negative ballot count.']);
                }

                $candidate = DB::table('governance_election_candidates')
                    ->where('tenant_id', $tenantId)
                    ->where('election_id', $electionId)
                    ->where('id', $candidateId)
                    ->lockForUpdate()
                    ->first(['id', 'status']);
                if ($candidate === null || $candidate->status !== 'eligible') {
                    throw ValidationException::withMessages([
                        'lines' => 'Count evidence may reference only a candidate with an approved eligible decision.',
                    ]);
                }

                if (array_key_exists($candidateId, $normalized)) {
                    throw ValidationException::withMessages(['lines' => 'A candidate may appear only once in a count batch.']);
                }
                $normalized[$candidateId] = $ballotCount;
            }
            if ($normalized === []) {
                throw ValidationException::withMessages(['lines' => 'At least one eligible candidate count is required.']);
            }

            foreach (['batch_reference', 'sealed_box_reference', 'count_sheet_reference'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([$field => 'A non-empty evidence reference is required.']);
                }
            }

            $now = Carbon::now();
            $batchId = (int) DB::table('governance_election_manual_count_batches')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'batch_reference' => trim((string) $data['batch_reference']),
                'polling_station' => isset($data['polling_station']) ? trim((string) $data['polling_station']) : null,
                'ballots_received' => $received,
                'ballots_valid' => $valid,
                'ballots_rejected' => $rejected,
                'sealed_box_reference' => trim((string) $data['sealed_box_reference']),
                'count_sheet_reference' => trim((string) $data['count_sheet_reference']),
                'entered_by' => $actorId,
                'witnessed_by' => null,
                'witnessed_at' => null,
                'status' => 'pending_witness',
                'counted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($normalized as $candidateId => $ballotCount) {
                DB::table('governance_election_manual_count_lines')->insert([
                    'tenant_id' => $tenantId,
                    'election_id' => $electionId,
                    'batch_id' => $batchId,
                    'candidate_id' => $candidateId,
                    'ballot_count' => $ballotCount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'batch' => DB::table('governance_election_manual_count_batches')
                    ->where('tenant_id', $tenantId)->where('election_id', $electionId)->where('id', $batchId)->first(),
                'lines' => DB::table('governance_election_manual_count_lines')
                    ->where('tenant_id', $tenantId)->where('election_id', $electionId)->where('batch_id', $batchId)
                    ->orderBy('id')->get()->all(),
            ];
        });
    }

    public function witness(int $tenantId, int $electionId, int $batchId, int $witnessUserId): object
    {
        return DB::transaction(function () use ($tenantId, $electionId, $batchId, $witnessUserId): object {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);
            $this->assertPhysicalCountingElection($election);

            $batch = DB::table('governance_election_manual_count_batches')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('id', $batchId)
                ->lockForUpdate()
                ->first();

            if ($batch === null || $batch->status !== 'pending_witness' || $batch->witnessed_by !== null) {
                throw ValidationException::withMessages(['batch' => 'Only a pending, unattested count batch may be witnessed.']);
            }

            $recorder = $this->activeCountMember($tenantId, $electionId, (int) $batch->entered_by, true);
            $witness = $this->activeCountMember($tenantId, $electionId, $witnessUserId, true);
            if ($recorder === null || $witness === null) {
                throw new AuthorizationException('The recorder and witness must remain active, non-recused committee or supervisory members with active tenant access.');
            }
            if ((int) $batch->entered_by === $witnessUserId
                || (int) $recorder->person_id === (int) $witness->person_id) {
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

    /** @return array{batches:array<int,object>,lines:array<int,object>} */
    public function listForElection(int $tenantId, int $electionId, int $actorId): array
    {
        if ($this->activeCountMember($tenantId, $electionId, $actorId) === null) {
            throw new AuthorizationException('Active, non-recused count committee membership is required.');
        }

        $batches = DB::table('governance_election_manual_count_batches')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->orderBy('counted_at')->orderBy('id')->get();
        $lines = DB::table('governance_election_manual_count_lines')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->orderBy('batch_id')->orderBy('id')->get();

        return ['batches' => $batches->all(), 'lines' => $lines->all()];
    }

    private function assertPhysicalCountingElection(GovernanceElection $election): void
    {
        if (!$election->isAuthorityVerified() || $election->status !== 'counting'
            || $election->voting_method !== 'physical') {
            throw ValidationException::withMessages([
                'election' => 'Manual physical count evidence is available only for an authority-verified physical election in counting status.',
            ]);
        }
    }

    private function activeCountMember(int $tenantId, int $electionId, int $userId, bool $lock = false): ?object
    {
        if ($userId < 1) {
            return null;
        }

        $query = DB::table('governance_election_committee_members as member')
            ->join('governance_election_authority_evidence as evidence', function ($join) use ($tenantId, $electionId): void {
                $join->on('evidence.id', '=', 'member.appointment_evidence_id')
                    ->where('evidence.tenant_id', '=', $tenantId)
                    ->where('evidence.election_id', '=', $electionId)
                    ->where('evidence.status', '=', 'verified');
            })
            ->join('people as person', 'person.id', '=', 'member.person_id')
            ->join('person_tenant_accesses as access', function ($join) use ($tenantId): void {
                $join->on('access.person_id', '=', 'person.id')
                    ->where('access.tenant_id', '=', $tenantId)
                    ->where('access.status', '=', 'active');
            })
            ->join('users as user', 'user.id', '=', 'member.user_id')
            ->where('member.tenant_id', $tenantId)
            ->where('member.election_id', $electionId)
            ->where('member.user_id', $userId)
            ->where('member.status', 'active')
            ->whereNull('member.recused_at')
            ->whereIn('member.member_role', ['committee_member', 'supervisory_member'])
            ->where('person.status', 'active')
            ->whereNull('person.deleted_at')
            ->where('user.is_active', true)
            ->whereNull('user.deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first(['member.id', 'member.person_id', 'member.user_id']);
    }
}
