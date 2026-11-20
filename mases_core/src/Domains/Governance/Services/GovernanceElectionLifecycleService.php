<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionLifecycleTransition;
use MaaleArna\MASES\Core\Exceptions\FileException;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;
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
            'governance_model' => 'share_company',
            'electorate_basis' => 'shareholders_on_record_date',
            'vote_weight_basis' => 'eligible_shares',
            'ballot_secrecy' => 'secret',
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
        if (trim((string) $reason) === '' || mb_strlen(trim((string) $reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A meaningful reason of at least five characters is required for every lifecycle transition.']);
        }
        if ($evidenceReferences === []) {
            throw ValidationException::withMessages(['evidence_references' => 'At least one durable evidence reference is required for every lifecycle transition.']);
        }
        foreach ($evidenceReferences as $reference) {
            if (!is_string($reference) || trim($reference) === '' || mb_strlen($reference) > 200) {
                throw ValidationException::withMessages(['evidence_references' => 'Each evidence reference must be a non-empty string of at most 200 characters.']);
            }
        }

        return DB::transaction(function () use ($tenantId, $electionId, $actor, $action, $expectedVersion, $reason, $evidenceReferences, $authority, $schedule): GovernanceElection {
            $election = GovernanceElection::query()->forTenant($tenantId)->whereKey($electionId)->lockForUpdate()->first();
            if (!$election) {
                throw (new ModelNotFoundException())->setModel(GovernanceElection::class, [$electionId]);
            }
            if ((int) $election->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'The election was changed by another request. Reload and retry with the current version.']);
            }

            $this->assertTransitionEvidence($tenantId, $actor, $evidenceReferences);

            $from = $election->status;
            $to = match ($action) {
                'authorize' => $this->authorizeElection($election, $tenantId, $actor, $authority),
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

    private function authorizeElection(GovernanceElection $election, int $tenantId, User $actor, array $authority): string
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

        $documentId = filter_var($authority['authority_document_id'] ?? null, FILTER_VALIDATE_INT);
        if ($documentId === false || $documentId === null || $documentId < 1) {
            throw ValidationException::withMessages(['authority_document_id' => 'A published authority evidence document is required.']);
        }

        $fileService = app(FileService::class);
        $this->assertEvidenceDocumentMatches(
            $fileService,
            $actor,
            $tenantId,
            $documentId,
            (string) $authority['authority_evidence_digest'],
            'authority_document_id',
            'authority_evidence_digest',
            'authority evidence',
        );

        $ruleSetDocumentId = filter_var($authority['rule_set_document_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ruleSetDocumentId === false || $ruleSetDocumentId === null || $ruleSetDocumentId < 1) {
            throw ValidationException::withMessages(['rule_set_document_id' => 'A published rule-set source document is required.']);
        }
        $this->assertEvidenceDocumentMatches(
            $fileService,
            $actor,
            $tenantId,
            $ruleSetDocumentId,
            (string) $authority['rule_set_digest'],
            'rule_set_document_id',
            'rule_set_digest',
            'rule-set',
        );

        $authorityEvidenceId = filter_var($authority['authority_evidence_id'] ?? null, FILTER_VALIDATE_INT);
        if ($authorityEvidenceId === false || $authorityEvidenceId === null || $authorityEvidenceId < 1) {
            throw ValidationException::withMessages(['authority_evidence_id' => 'A separately reviewed and verified authority evidence record is required.']);
        }
        $authorityEvidence = DB::table('governance_election_authority_evidence')
            ->where('tenant_id', $tenantId)
            ->where('election_id', (int) $election->getKey())
            ->where('id', $authorityEvidenceId)
            ->where('document_id', $documentId)
            ->where('content_sha256', strtolower((string) $authority['authority_evidence_digest']))
            ->where('status', 'verified')
            ->whereNotNull('reviewed_by')
            ->whereNotNull('verified_by')
            ->whereNotNull('verified_at')
            ->whereNotNull('verification_reason')
            ->where('verification_reason', '<>', '')
            ->whereColumn('recorded_by', '<>', 'reviewed_by')
            ->whereColumn('recorded_by', '<>', 'verified_by')
            ->first();
        if (!$authorityEvidence
            || (int) $election->authority_evidence_id !== (int) $authorityEvidenceId
            || (int) $election->authority_verified_by !== (int) $authorityEvidence->verified_by
            || trim((string) $authorityEvidence->instrument_reference) !== trim((string) $authority['authority_reference'])) {
            throw ValidationException::withMessages(['authority_evidence_id' => 'The verified authority record must match this election, its published document, digest, and instrument reference.']);
        }

        $verifiedAt = now();
        $election->fill([
            'authority_reference' => trim($authority['authority_reference']),
            'authority_document_id' => $documentId,
            'authority_evidence_digest' => strtolower($authority['authority_evidence_digest']),
            'authority_version' => trim($authority['authority_version']),
            'authority_evidence_id' => (int) $authorityEvidenceId,
            'authority_verified_by' => (int) $authorityEvidence->verified_by,
            'authority_verified_at' => $authorityEvidence->verified_at,
            'authority_verification_reason' => $authorityEvidence->verification_reason,
            'authority_snapshot' => [
                'schema_version' => '1',
                'authority' => [
                    'evidence_record_id' => (int) $authorityEvidenceId,
                    'document_id' => $documentId,
                    'sha256' => strtolower($authority['authority_evidence_digest']),
                    'reference' => trim($authority['authority_reference']),
                    'version' => trim($authority['authority_version']),
                    'verified_at' => (string) $authorityEvidence->verified_at,
                    'verified_by' => (int) $authorityEvidence->verified_by,
                    'verification_reason' => (string) $authorityEvidence->verification_reason,
                ],
                'rule_set' => [
                    'document_id' => $ruleSetDocumentId,
                    'sha256' => strtolower($authority['rule_set_digest']),
                    'version' => trim($authority['rule_set_version']),
                ],
                'authorized_at' => $verifiedAt->toISOString(),
                'authorized_by' => (int) $actor->getAuthIdentifier(),
            ],
            'rule_set_version' => trim($authority['rule_set_version']),
            'rule_set_digest' => strtolower($authority['rule_set_digest']),
            'rule_set_document_id' => $ruleSetDocumentId,
            'authorized_at' => $verifiedAt,
        ]);
        return 'authorized';
    }

    private function assertTransitionEvidence(int $tenantId, User $actor, array $references): void
    {
        $fileService = app(FileService::class);
        foreach ($references as $index => $reference) {
            if (!is_string($reference) || !preg_match('/^file:([1-9][0-9]*):([a-f0-9]{64})$/i', $reference, $matches)) {
                throw ValidationException::withMessages([
                    "evidence_references.{$index}" => 'Evidence must identify a published File Management document as file:{document_id}:{sha256}.',
                ]);
            }
            $this->assertEvidenceDocumentMatches(
                $fileService,
                $actor,
                $tenantId,
                (int) $matches[1],
                strtolower($matches[2]),
                "evidence_references.{$index}",
                "evidence_references.{$index}",
                'transition evidence',
            );
        }
    }

    private function assertEvidenceDocumentMatches(
        FileService $fileService,
        User $actor,
        int $tenantId,
        int $documentId,
        string $expectedDigest,
        string $documentField,
        string $digestField,
        string $evidenceLabel,
    ): void {
        try {
            // Canonical resolution enforces file.view and excludes soft-deleted files.
            $document = $fileService->find($documentId, false, $actor);
        } catch (FileException) {
            $document = null;
        }

        if (!$document || (int) $document->tenant_id !== $tenantId || $document->status !== 'published') {
            throw ValidationException::withMessages([$documentField => "The {$evidenceLabel} source must be published, non-deleted, in this tenant, and accessible to the actor."]);
        }

        try {
            // FileService enforces private-file download permission and storage access.
            $response = $fileService->download($document, $actor);
            $bytes = $response->getContent();
        } catch (FileException) {
            $bytes = false;
        }

        if (!is_string($bytes) || $bytes === '' || !hash_equals(strtolower($expectedDigest), hash('sha256', $bytes))) {
            throw ValidationException::withMessages([$digestField => "The {$evidenceLabel} bytes could not be read or do not match the supplied SHA-256 digest."]);
        }
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
        $timezone = (string) ($schedule['timezone'] ?? $election->timezone);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'A valid IANA timezone identifier is required.']);
        }
        $zone = new DateTimeZone($timezone);
        $startAt = $this->parseStrictDateTime($start, $zone, 'scheduled_start_at');
        $endAt = $this->parseStrictDateTime($end, $zone, 'scheduled_end_at');
        if ($startAt >= $endAt) {
            throw ValidationException::withMessages(['scheduled_start_at' => 'A valid start time earlier than the end time is required.']);
        }
        $election->scheduled_start_at = $startAt->format('Y-m-d H:i:s');
        $election->scheduled_end_at = $endAt->format('Y-m-d H:i:s');
        $election->timezone = $timezone;
        return 'scheduled';
    }

    private function parseStrictDateTime(mixed $value, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/', $value)) {
            throw ValidationException::withMessages([$field => 'Use an unambiguous RFC 3339 date-time with seconds and an explicit UTC offset.']);
        }

        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d\\TH:i:sP') !== $normalized) {
            throw ValidationException::withMessages([$field => 'The date-time is invalid or ambiguous.']);
        }

        return $parsed->setTimezone($zone);
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
