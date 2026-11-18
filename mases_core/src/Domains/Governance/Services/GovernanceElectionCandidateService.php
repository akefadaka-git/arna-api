<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionCandidate;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionNominationWindow;
use MaaleArna\MASES\Core\Files\Services\FileService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeSecurityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GovernanceElectionCandidateService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly GovernanceElectionCommitteeSecurityService $committeeSecurity,
        private readonly FileService $files,
    ) {
    }

    public function configureNominationWindow(
        int $tenantId,
        int $electionId,
        User $actor,
        string $opensAt,
        string $closesAt,
        string $ruleSetVersion,
        string $ruleSetDigest,
        string $authorityReference,
    ): GovernanceElectionNominationWindow {
        $this->authorize($actor, $tenantId, 'governance.election.manage');
        if (!preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/i', trim($opensAt))
            || !preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/i', trim($closesAt))
            || trim($ruleSetVersion) === '' || !preg_match('/^[a-f0-9]{64}$/i', $ruleSetDigest)
            || trim($authorityReference) === '') {
            throw ValidationException::withMessages(['nomination_window' => 'Opening and closing timestamps must include an explicit UTC offset; a versioned SHA-256 rule-set digest and authoritative source reference are also required.']);
        }
        $opensInput = new \DateTimeImmutable($opensAt);
        $closesInput = new \DateTimeImmutable($closesAt);

        return DB::transaction(function () use ($tenantId, $electionId, $actor, $opensInput, $closesInput, $ruleSetVersion, $ruleSetDigest, $authorityReference): GovernanceElectionNominationWindow {
            $election = GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->lockForUpdate()->first();
            if (!$election) {
                throw new NotFoundHttpException('Election not found in this tenant.');
            }
            if (!in_array($election->status, ['authorized', 'scheduled'], true)
                || blank($election->rule_set_version) || blank($election->rule_set_digest)
                || !hash_equals((string) $election->rule_set_version, $ruleSetVersion)
                || !hash_equals(strtolower((string) $election->rule_set_digest), strtolower($ruleSetDigest))
                || blank($election->authority_reference)
                || !hash_equals((string) $election->authority_reference, trim($authorityReference))) {
                throw new ConflictHttpException('The election must be authorized with the same authoritative, versioned rule set before nomination intake can be configured.');
            }

            $timezone = new \DateTimeZone((string) $election->timezone);
            $opens = $opensInput->setTimezone($timezone);
            $closes = $closesInput->setTimezone($timezone);
            if ($opens >= $closes) {
                throw ValidationException::withMessages(['nomination_window' => 'The opening time must precede the closing time.']);
            }
            $window = GovernanceElectionNominationWindow::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->lockForUpdate()->first();
            if ($window && $window->status !== 'draft') {
                throw new ConflictHttpException('An opened or closed nomination window is immutable.');
            }
            $values = [
                'status' => 'draft',
                'opens_at' => $opens->format('Y-m-d H:i:s'),
                'closes_at' => $closes->format('Y-m-d H:i:s'),
                'rule_set_version' => $ruleSetVersion,
                'rule_set_digest' => strtolower($ruleSetDigest),
                'authority_reference' => trim($authorityReference),
                'created_by' => (int) $actor->getAuthIdentifier(),
                'version' => $window ? ((int) $window->version + 1) : 1,
            ];
            if ($window) {
                $window->fill($values);
                $window->save();
            } else {
                $window = GovernanceElectionNominationWindow::query()->create($values + [
                    'tenant_id' => $tenantId,
                    'election_id' => $electionId,
                ]);
            }
            $window->refresh();
            $this->appendWindowEvent($tenantId, $electionId, (int) $window->id, 'configured', $actor, [
                'opens_at' => $window->opens_at?->toIso8601String(),
                'closes_at' => $window->closes_at?->toIso8601String(),
                'rule_set_version' => $window->rule_set_version,
                'rule_set_digest' => $window->rule_set_digest,
                'authority_reference' => $window->authority_reference,
            ]);
            return $window;
        });
    }

    public function openNominationWindow(int $tenantId, int $electionId, User $actor, int $expectedVersion): GovernanceElectionNominationWindow
    {
        $this->authorize($actor, $tenantId, 'governance.election.manage');
        return DB::transaction(function () use ($tenantId, $electionId, $actor, $expectedVersion): GovernanceElectionNominationWindow {
            $window = GovernanceElectionNominationWindow::query()->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->lockForUpdate()->first();
            if (!$window) {
                throw new NotFoundHttpException('Nomination window is not configured.');
            }
            if ((int) $window->version !== $expectedVersion || $window->status !== 'draft') {
                throw new ConflictHttpException('Nomination window changed or is not in draft state.');
            }
            $election = GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->lockForUpdate()->first();
            $now = now($election?->timezone ?: config('app.timezone'));
            if ($now->lt($window->opens_at) || $now->gte($window->closes_at)) {
                throw new ConflictHttpException('The configured opening time has not arrived or the closing time has passed.');
            }
            if (!$election || !in_array($election->status, ['authorized', 'scheduled'], true)
                || !hash_equals((string) $election->rule_set_version, (string) $window->rule_set_version)
                || !hash_equals((string) $election->rule_set_digest, (string) $window->rule_set_digest)) {
                throw new ConflictHttpException('Election authority or rule-set evidence no longer matches the configured nomination window.');
            }
            $window->status = 'open';
            $window->opened_by = (int) $actor->getAuthIdentifier();
            $window->opened_at = $now;
            $window->version = $expectedVersion + 1;
            $window->save();
            $window->refresh();
            $this->appendWindowEvent($tenantId, $electionId, (int) $window->id, 'opened', $actor, [
                'opened_at' => $window->opened_at?->toIso8601String(),
                'aggregate_version' => (int) $window->version,
            ]);
            return $window;
        });
    }

    public function closeNominationWindow(int $tenantId, int $electionId, User $actor, int $expectedVersion, string $reason): GovernanceElectionNominationWindow
    {
        $this->authorize($actor, $tenantId, 'governance.election.manage');
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A meaningful closure reason is required.']);
        }
        return DB::transaction(function () use ($tenantId, $electionId, $actor, $expectedVersion, $reason): GovernanceElectionNominationWindow {
            $window = GovernanceElectionNominationWindow::query()->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->lockForUpdate()->first();
            if (!$window) {
                throw new NotFoundHttpException('Nomination window not found.');
            }
            if ($window->status !== 'open' || (int) $window->version !== $expectedVersion) {
                throw new ConflictHttpException('Nomination window is not open or its version is stale.');
            }
            $window->status = 'closed';
            $window->closed_by = (int) $actor->getAuthIdentifier();
            $window->closed_at = now();
            $window->closure_reason = trim($reason);
            $window->version = $expectedVersion + 1;
            $window->save();
            $window->refresh();
            $this->appendWindowEvent($tenantId, $electionId, (int) $window->id, 'closed', $actor, [
                'closed_at' => $window->closed_at?->toIso8601String(),
                'reason' => trim($reason),
                'aggregate_version' => (int) $window->version,
            ]);
            return $window;
        });
    }

    public function submitNomination(
        int $tenantId,
        int $electionId,
        User $actor,
        int $candidatePersonId,
        string $idempotencyKey,
        ?string $statement = null,
    ): object {
        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'A non-empty idempotency key of at most 100 characters is required.']);
        }
        if ($statement !== null && mb_strlen($statement) > 10000) {
            throw ValidationException::withMessages(['statement' => 'The nomination statement exceeds 10,000 characters.']);
        }

        return DB::transaction(function () use ($tenantId, $electionId, $actor, $candidatePersonId, $idempotencyKey, $statement): object {
            $election = GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->lockForUpdate()->first();
            if (!$election) {
                throw new NotFoundHttpException('Election not found in this tenant.');
            }
            $window = GovernanceElectionNominationWindow::query()->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->lockForUpdate()->first();
            $now = now($election->timezone ?: config('app.timezone'));
            if (!$window || $window->status !== 'open' || $now->lt($window->opens_at) || $now->gte($window->closes_at)
                || !in_array($election->status, ['authorized', 'scheduled'], true)) {
                throw new ConflictHttpException('The authoritative nomination window is not open.');
            }
            if (!hash_equals(strtolower((string) $election->rule_set_digest), strtolower((string) $window->rule_set_digest))
                || !hash_equals((string) $election->rule_set_version, (string) $window->rule_set_version)) {
                throw new ConflictHttpException('Nomination rule-set evidence has changed; intake is blocked.');
            }

            $nominator = $this->resolveNominator($tenantId, $actor);
            $person = DB::table('people')->where('id', $candidatePersonId)->where('status', 'active')
                ->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$person || !DB::table('person_tenant_accesses')->where('person_id', $candidatePersonId)
                ->where('tenant_id', $tenantId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['candidate_person_id' => 'Candidate must be an active canonical Person with active access to this tenant.']);
            }

            $candidateShareholders = DB::table('shareholders')->where('tenant_id', $tenantId)
                ->where('person_id', $candidatePersonId)->where('is_active', true)->whereNull('deleted_at')
                ->limit(2)->get(['id', 'shareholder_type', 'person_id']);
            if ($candidateShareholders->count() > 1) {
                throw ValidationException::withMessages(['candidate_person_id' => 'Candidate Person has ambiguous active shareholder links; intake fails closed.']);
            }
            if ($candidateShareholders->count() === 1 && $candidateShareholders->first()->shareholder_type !== 'individual') {
                throw ValidationException::withMessages(['candidate_person_id' => 'A legal entity cannot be registered as a natural-person candidate.']);
            }

            $selfNomination = $nominator->shareholder_type === 'individual'
                && (int) ($nominator->person_id ?? 0) === $candidatePersonId
                && (int) ($nominator->user_id ?? 0) === (int) $actor->getAuthIdentifier()
                && (int) ($person->user_id ?? 0) === (int) $actor->getAuthIdentifier();

            $key = hash('sha256', trim($idempotencyKey));
            $payloadDigest = hash('sha256', json_encode([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'candidate_person_id' => $candidatePersonId,
                'nominator_shareholder_id' => (int) $nominator->id,
                'submitted_by_user_id' => (int) $actor->getAuthIdentifier(),
                'self_nomination' => $selfNomination,
                'statement' => $statement,
                'rule_set_version' => (string) $window->rule_set_version,
                'rule_set_digest' => (string) $window->rule_set_digest,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $existing = DB::table('governance_election_nomination_submissions')
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if (!hash_equals((string) $existing->payload_digest, $payloadDigest)) {
                    throw new ConflictHttpException('Idempotency key was already used for a different nomination payload.');
                }
                return (object) [
                    'id' => (int) $existing->id,
                    'candidate_id' => (int) $existing->candidate_id,
                    'status' => $existing->status,
                    'self_nomination' => (bool) $existing->self_nomination,
                    'submitted_at' => $existing->submitted_at,
                ];
            }

            $candidate = GovernanceElectionCandidate::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'election_id' => $electionId, 'person_id' => $candidatePersonId],
                ['status' => 'intake_pending', 'consent_status' => 'pending', 'version' => 1],
            );
            $candidate = GovernanceElectionCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            $duplicate = DB::table('governance_election_nomination_submissions')->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->where('candidate_id', $candidate->id)
                ->where('nominated_by_shareholder_id', $nominator->id)->exists();
            if ($duplicate) {
                throw new ConflictHttpException('This shareholder has already submitted a nomination for this candidate. Use the correction or withdrawal process.');
            }

            $submissionId = DB::table('governance_election_nomination_submissions')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'candidate_id' => $candidate->id,
                'nominated_by_shareholder_id' => $nominator->id,
                'submitted_by_user_id' => $actor->getAuthIdentifier(),
                'self_nomination' => $selfNomination,
                'status' => 'submitted',
                'idempotency_key' => $key,
                'payload_digest' => $payloadDigest,
                'rule_set_version' => $window->rule_set_version,
                'rule_set_digest' => $window->rule_set_digest,
                'statement' => $statement,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->appendNominationEvent($tenantId, $electionId, (int) $candidate->id, $submissionId, 'submitted', $actor, [
                'payload_digest' => $payloadDigest,
                'rule_set_version' => $window->rule_set_version,
                'rule_set_digest' => $window->rule_set_digest,
            ]);
            $this->appendCandidateEvent($tenantId, $electionId, (int) $candidate->id, 'nomination.submitted', $actor, [
                'nomination_submission_id' => $submissionId,
                'nominated_by_shareholder_id' => (int) $nominator->id,
                'self_nomination' => $selfNomination,
            ]);
            return (object) [
                'id' => $submissionId,
                'candidate_id' => (int) $candidate->id,
                'status' => 'submitted',
                'self_nomination' => $selfNomination,
                'submitted_at' => now()->toDateTimeString(),
            ];
        });
    }

    public function recordConsent(
        int $tenantId,
        int $electionId,
        int $candidateId,
        User $actor,
        string $method,
        array $evidenceFileIds = [],
    ): GovernanceElectionCandidate {
        return DB::transaction(function () use ($tenantId, $electionId, $candidateId, $actor, $method, $evidenceFileIds): GovernanceElectionCandidate {
            $candidate = GovernanceElectionCandidate::query()->forElection($tenantId, $electionId)
                ->whereKey($candidateId)->lockForUpdate()->first();
            if (!$candidate) {
                throw new NotFoundHttpException('Candidate not found in this tenant and election.');
            }
            if ($candidate->consent_status !== 'pending') {
                throw new ConflictHttpException('Candidate consent has already been recorded and cannot be overwritten.');
            }
            if (!$this->hasActiveNomination($tenantId, $electionId, $candidateId)) {
                throw new ConflictHttpException('Candidate consent cannot be recorded before an active nomination exists.');
            }
            $person = DB::table('people')->where('id', $candidate->person_id)->where('status', 'active')
                ->whereNull('deleted_at')->first();
            if (!$person) {
                throw new ConflictHttpException('Candidate Person is no longer active.');
            }

            if ($method === 'direct') {
                if ((int) ($person->user_id ?? 0) !== (int) $actor->getAuthIdentifier()) {
                    throw new AuthorizationException('Direct consent must be submitted by the candidate Person’s directly linked account.');
                }
            } elseif ($method === 'documented') {
                $this->authorize($actor, $tenantId, 'governance.election.manage');
                if ($evidenceFileIds === []) {
                    throw ValidationException::withMessages(['evidence_file_ids' => 'Documented consent requires at least one published evidence file.']);
                }
                foreach ($evidenceFileIds as $fileId) {
                    $this->attachEvidenceRecord($tenantId, $electionId, $candidate, $actor, (int) $fileId, 'consent');
                }
            } else {
                throw ValidationException::withMessages(['method' => 'Consent method must be direct or documented.']);
            }

            $candidate->consent_status = 'accepted';
            $candidate->consent_method = $method;
            $candidate->consented_at = now();
            $candidate->consented_by = (int) $actor->getAuthIdentifier();
            $candidate->status = 'eligibility_pending';
            $candidate->version = (int) $candidate->version + 1;
            $candidate->save();
            $this->appendCandidateEvent($tenantId, $electionId, $candidateId, 'candidate.consent.accepted', $actor, [
                'method' => $method,
                'evidence_file_ids' => array_values(array_map('intval', $evidenceFileIds)),
                'aggregate_version' => (int) $candidate->version,
            ]);
            return $candidate->refresh();
        });
    }

    public function attachEvidence(
        int $tenantId,
        int $electionId,
        int $candidateId,
        User $actor,
        int $fileId,
        string $evidenceType,
    ): object {
        $this->authorize($actor, $tenantId, 'governance.election.manage');
        if (!in_array($evidenceType, ['identity', 'qualification', 'declaration', 'consent', 'other'], true)) {
            throw ValidationException::withMessages(['evidence_type' => 'Unsupported candidate evidence type.']);
        }
        return DB::transaction(function () use ($tenantId, $electionId, $candidateId, $actor, $fileId, $evidenceType): object {
            $candidate = GovernanceElectionCandidate::query()->forElection($tenantId, $electionId)
                ->whereKey($candidateId)->lockForUpdate()->first();
            if (!$candidate) {
                throw new NotFoundHttpException('Candidate not found.');
            }
            return $this->attachEvidenceRecord($tenantId, $electionId, $candidate, $actor, $fileId, $evidenceType);
        });
    }

    public function decideEligibility(
        int $tenantId,
        int $electionId,
        int $candidateId,
        User $actor,
        int $expectedVersion,
        string $outcome,
        string $rationale,
        array $evidenceIds,
    ): GovernanceElectionCandidate {
        if (!in_array($outcome, ['eligible', 'ineligible', 'deferred_unresolved'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Decision must be eligible, ineligible or deferred_unresolved.']);
        }
        if (mb_strlen(trim($rationale)) < 5 || $evidenceIds === []) {
            throw ValidationException::withMessages(['rationale' => 'A meaningful rationale and at least one candidate evidence reference are required.']);
        }

        return DB::transaction(function () use ($tenantId, $electionId, $candidateId, $actor, $expectedVersion, $outcome, $rationale, $evidenceIds): GovernanceElectionCandidate {
            $candidate = GovernanceElectionCandidate::query()->forElection($tenantId, $electionId)
                ->whereKey($candidateId)->lockForUpdate()->first();
            if (!$candidate) {
                throw new NotFoundHttpException('Candidate not found.');
            }
            if ((int) $candidate->version !== $expectedVersion) {
                throw new ConflictHttpException('Candidate was changed by another request; reload and retry.');
            }
            if ($candidate->consent_status !== 'accepted' || !$this->hasActiveNomination($tenantId, $electionId, $candidateId)) {
                throw new ConflictHttpException('Eligibility cannot be decided until candidate consent and an active nomination are recorded.');
            }
            if (!in_array($candidate->status, ['eligibility_pending', 'deferred_unresolved'], true)) {
                throw new ConflictHttpException('A final eligibility decision cannot be overwritten in this module; use the independent objections and appeals process.');
            }
            $election = GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->firstOrFail();
            $window = GovernanceElectionNominationWindow::query()->where('tenant_id', $tenantId)->where('election_id', $electionId)->firstOrFail();
            if (!hash_equals((string) $election->rule_set_version, (string) $window->rule_set_version)
                || !hash_equals(strtolower((string) $election->rule_set_digest), strtolower((string) $window->rule_set_digest))) {
                throw new ConflictHttpException('The authoritative rule set changed; eligibility decision is blocked.');
            }
            $evidence = DB::table('governance_election_candidate_evidence')->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->where('candidate_id', $candidateId)
                ->whereIn('id', array_map('intval', $evidenceIds))->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count(array_unique(array_map('intval', $evidenceIds))) !== count($evidence)) {
                throw ValidationException::withMessages(['evidence_ids' => 'Every decision evidence reference must belong to this candidate and election.']);
            }

            $this->committeeSecurity->assertMayDecide($tenantId, $electionId, (int) $candidate->person_id, $actor);

            $nextVersion = $expectedVersion + 1;
            DB::table('governance_election_candidate_decisions')->insert([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'candidate_id' => $candidateId,
                'outcome' => $outcome,
                'rule_set_version' => $window->rule_set_version,
                'rule_set_digest' => $window->rule_set_digest,
                'rationale' => trim($rationale),
                'evidence_references' => json_encode($evidence, JSON_THROW_ON_ERROR),
                'decided_by' => $actor->getAuthIdentifier(),
                'aggregate_version' => $nextVersion,
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $candidate->status = $outcome;
            $candidate->version = $nextVersion;
            $candidate->save();
            $this->appendCandidateEvent($tenantId, $electionId, $candidateId, 'candidate.eligibility.decided', $actor, [
                'outcome' => $outcome,
                'rule_set_version' => $window->rule_set_version,
                'rule_set_digest' => $window->rule_set_digest,
                'decision_version' => $nextVersion,
            ]);
            return $candidate->refresh();
        });
    }

    public function withdrawNomination(int $tenantId, int $electionId, int $submissionId, User $actor, string $reason): object
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A meaningful withdrawal reason is required.']);
        }
        return DB::transaction(function () use ($tenantId, $electionId, $submissionId, $actor, $reason): object {
            $submission = DB::table('governance_election_nomination_submissions')->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->where('id', $submissionId)->lockForUpdate()->first();
            if (!$submission) {
                throw new NotFoundHttpException('Nomination not found.');
            }
            $nominator = $this->resolveNominator($tenantId, $actor);
            if ((int) $nominator->id !== (int) $submission->nominated_by_shareholder_id) {
                throw new AuthorizationException('Only the submitting shareholder or its current authorized representative may withdraw this nomination.');
            }
            $withdrawn = DB::table('governance_election_nomination_events')->where('nomination_submission_id', $submissionId)
                ->whereIn('event_type', ['withdrawn'])->exists();
            if ($withdrawn) {
                throw new ConflictHttpException('Nomination has already been withdrawn.');
            }
            $this->appendNominationEvent($tenantId, $electionId, (int) $submission->candidate_id, $submissionId, 'withdrawn', $actor, ['reason' => trim($reason)]);
            $this->appendCandidateEvent($tenantId, $electionId, (int) $submission->candidate_id, 'nomination.withdrawn', $actor, [
                'nomination_submission_id' => $submissionId,
                'reason' => trim($reason),
            ]);
            return (object) [
                'id' => (int) $submission->id,
                'candidate_id' => (int) $submission->candidate_id,
                'status' => 'withdrawn',
            ];
        });
    }

    public function correctNomination(int $tenantId, int $electionId, int $submissionId, User $actor, string $statement, string $reason): object
    {
        if (trim($statement) === '' || mb_strlen($statement) > 10000 || mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['correction' => 'A non-empty corrected statement (maximum 10,000 characters) and meaningful reason are required.']);
        }
        return DB::transaction(function () use ($tenantId, $electionId, $submissionId, $actor, $statement, $reason): object {
            $submission = DB::table('governance_election_nomination_submissions')->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->where('id', $submissionId)->lockForUpdate()->first();
            if (!$submission) {
                throw new NotFoundHttpException('Nomination not found.');
            }
            $nominator = $this->resolveNominator($tenantId, $actor);
            if ((int) $nominator->id !== (int) $submission->nominated_by_shareholder_id) {
                throw new AuthorizationException('Only the submitting shareholder may correct this nomination.');
            }
            $withdrawn = DB::table('governance_election_nomination_events')->where('nomination_submission_id', $submissionId)
                ->where('event_type', 'withdrawn')->exists();
            if ($withdrawn) {
                throw new ConflictHttpException('A withdrawn nomination cannot be corrected.');
            }
            $this->appendNominationEvent($tenantId, $electionId, (int) $submission->candidate_id, $submissionId, 'corrected', $actor, [
                'statement' => $statement,
                'reason' => trim($reason),
                'supersedes_payload_digest' => (string) $submission->payload_digest,
            ]);
            $this->appendCandidateEvent($tenantId, $electionId, (int) $submission->candidate_id, 'nomination.corrected', $actor, [
                'nomination_submission_id' => $submissionId,
                'reason' => trim($reason),
            ]);
            return (object) [
                'id' => (int) $submission->id,
                'candidate_id' => (int) $submission->candidate_id,
                'status' => 'corrected',
            ];
        });
    }

    public function listCandidates(int $tenantId, int $electionId, User $actor): array
    {
        $this->authorize($actor, $tenantId, 'governance.election.view');
        GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->firstOrFail();
        return GovernanceElectionCandidate::query()->forElection($tenantId, $electionId)
            ->select(['id', 'tenant_id', 'election_id', 'person_id', 'status', 'consent_status', 'version', 'created_at'])
            ->with([
                'person:id,public_id,first_name_am,father_name_am,grandfather_name_am,first_name_en,father_name_en,grandfather_name_en',
                'nominations:id,candidate_id,status,self_nomination,statement,submitted_at',
                'evidence:id,candidate_id,evidence_type,attached_at',
                'decisions:id,candidate_id,outcome,rationale,rule_set_version,decided_at',
            ])
            ->orderBy('id')->get()->all();
    }

    private function resolveNominator(int $tenantId, User $actor): object
    {
        $actorId = (int) $actor->getAuthIdentifier();
        // Legal-entity representative nomination authority is not assumed.
        // It remains blocked until the operative governing rules are encoded
        // in an approved rule-set revision and this service is amended.
        $query = DB::table('shareholders')->where('tenant_id', $tenantId)
            ->where('shareholder_type', 'individual')
            ->where('is_active', true)->whereNull('deleted_at')
            ->where('user_id', $actorId)->limit(2);
        $matches = $query->get();
        if ($matches->count() !== 1) {
            throw new AuthorizationException('The authenticated account must resolve to exactly one active shareholder identity or authorized legal-entity representative in this tenant.');
        }
        return $matches->first();
    }

    private function attachEvidenceRecord(
        int $tenantId,
        int $electionId,
        GovernanceElectionCandidate $candidate,
        User $actor,
        int $fileId,
        string $evidenceType,
    ): object {
        $file = $this->files->find($fileId);
        if (!$file || (int) $file->tenant_id !== $tenantId || $file->status !== 'published' || $file->deleted_at !== null) {
            throw ValidationException::withMessages(['file_id' => 'Evidence must be an accessible, published, non-deleted file belonging to this tenant.']);
        }
        $metadataDigest = hash('sha256', json_encode([
            'tenant_id' => $tenantId,
            'file_id' => (int) $file->id,
            'name' => (string) $file->name,
            'mime_type' => (string) $file->mime_type,
            'size' => (int) $file->size,
            'version' => (int) $file->version,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $id = DB::table('governance_election_candidate_evidence')->insertGetId([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'candidate_id' => $candidate->id,
            'file_id' => $fileId,
            'evidence_type' => $evidenceType,
            'metadata_digest' => $metadataDigest,
            'attached_by' => $actor->getAuthIdentifier(),
            'attached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->appendCandidateEvent($tenantId, $electionId, (int) $candidate->id, 'candidate.evidence.attached', $actor, [
            'evidence_id' => $id,
            'file_id' => $fileId,
            'evidence_type' => $evidenceType,
            'metadata_digest' => $metadataDigest,
        ]);
        return DB::table('governance_election_candidate_evidence')->where('id', $id)->first();
    }

    private function hasActiveNomination(int $tenantId, int $electionId, int $candidateId): bool
    {
        return DB::table('governance_election_nomination_submissions as submissions')
            ->where('submissions.tenant_id', $tenantId)
            ->where('submissions.election_id', $electionId)
            ->where('submissions.candidate_id', $candidateId)
            ->where('submissions.status', 'submitted')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('governance_election_nomination_events as events')
                    ->whereColumn('events.nomination_submission_id', 'submissions.id')
                    ->where('events.event_type', 'withdrawn');
            })->exists();
    }

    private function appendWindowEvent(int $tenantId, int $electionId, int $windowId, string $eventType, User $actor, array $payload): void
    {
        $sequence = (int) DB::table('governance_election_nomination_window_events')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->where('window_id', $windowId)->max('sequence') + 1;
        DB::table('governance_election_nomination_window_events')->insert([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'window_id' => $windowId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'actor_user_id' => $actor->getAuthIdentifier(),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function appendNominationEvent(
        int $tenantId,
        int $electionId,
        int $candidateId,
        int $submissionId,
        string $eventType,
        User $actor,
        array $payload,
    ): void {
        $sequence = (int) DB::table('governance_election_nomination_events')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->where('nomination_submission_id', $submissionId)->max('sequence') + 1;
        DB::table('governance_election_nomination_events')->insert([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'candidate_id' => $candidateId,
            'nomination_submission_id' => $submissionId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'actor_user_id' => $actor->getAuthIdentifier(),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function appendCandidateEvent(int $tenantId, int $electionId, int $candidateId, string $eventType, User $actor, array $payload): void
    {
        $sequence = (int) DB::table('governance_election_candidate_events')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->where('candidate_id', $candidateId)->max('sequence') + 1;
        DB::table('governance_election_candidate_events')->insert([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'candidate_id' => $candidateId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'actor_user_id' => $actor->getAuthIdentifier(),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authorize(User $actor, int $tenantId, string $permission): void
    {
        if (!$this->authorization->hasPermission(permissionSlug: $permission, user: $actor, tenantId: $tenantId)) {
            throw new AuthorizationException('The required election permission is not granted in this tenant.');
        }
    }
}
