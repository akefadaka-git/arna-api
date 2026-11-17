<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class GovernanceElectionDisputeEventLedger
{
    private const SUBJECT_TABLES = [
        'objection' => 'governance_election_objections',
        'appeal' => 'governance_election_appeals',
    ];

    /**
     * Append one immutable-by-contract event. The election row is the
     * serialization lock, preventing concurrent writers from forking a
     * subject's hash chain on MySQL or PostgreSQL.
     *
     * @throws JsonException
     */
    public function append(
        int $tenantId,
        int $electionId,
        string $subjectType,
        int $subjectId,
        string $eventType,
        ?int $actorUserId,
        array $payload = [],
    ): object {
        $this->assertInput($tenantId, $electionId, $subjectType, $subjectId, $eventType, $actorUserId);

        return DB::transaction(function () use (
            $tenantId,
            $electionId,
            $subjectType,
            $subjectId,
            $eventType,
            $actorUserId,
            $payload,
        ): object {
            $election = DB::table('governance_elections')
                ->where('id', $electionId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first(['id']);

            if ($election === null) {
                throw (new ModelNotFoundException())->setModel('GovernanceElection');
            }

            if ($actorUserId !== null) {
                $person = DB::table('people')
                    ->where('user_id', $actorUserId)
                    ->whereNull('deleted_at')
                    ->first(['id']);

                $actorIsActive = DB::table('users')
                    ->where('id', $actorUserId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->exists();

                $actorHasTenantAccess = $person !== null && DB::table('person_tenant_accesses')
                    ->where('person_id', $person->id)
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->exists();

                if (!$actorIsActive || !$actorHasTenantAccess) {
                    throw new InvalidArgumentException('Event actor must be an active account with active access to the event tenant.');
                }
            }

            $subject = DB::table(self::SUBJECT_TABLES[$subjectType])
                ->where('id', $subjectId)
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->lockForUpdate()
                ->first(['id']);

            if ($subject === null) {
                throw (new ModelNotFoundException())->setModel('ElectionDispute');
            }

            $previous = DB::table('governance_election_dispute_events')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['event_sha256']);

            $previousHash = $previous?->event_sha256;
            $occurredAt = now()->format('Y-m-d H:i:s');
            $canonicalPayload = $this->canonicalize($payload);
            $payloadJson = json_encode($canonicalPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hash = $this->eventHash(
                $tenantId,
                $electionId,
                $subjectType,
                $subjectId,
                $eventType,
                $actorUserId,
                $canonicalPayload,
                $previousHash,
                $occurredAt,
            );

            $id = DB::table('governance_election_dispute_events')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'event_type' => $eventType,
                'actor_user_id' => $actorUserId,
                'event_payload' => $payloadJson,
                'previous_event_sha256' => $previousHash,
                'event_sha256' => $hash,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return DB::table('governance_election_dispute_events')->where('id', $id)->first();
        }, 3);
    }

    /**
     * Verify the complete subject chain from its first event to its latest.
     * Returns false on a broken link, malformed payload, or digest mismatch.
     */
    public function verify(int $tenantId, int $electionId, string $subjectType, int $subjectId): bool
    {
        if ($tenantId < 1 || $electionId < 1 || $subjectId < 1 || !isset(self::SUBJECT_TABLES[$subjectType])) {
            return false;
        }

        $events = DB::table('governance_election_dispute_events')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('id')
            ->get();

        $previousHash = null;

        foreach ($events as $event) {
            if ($event->previous_event_sha256 !== $previousHash) {
                return false;
            }

            try {
                $payload = is_array($event->event_payload)
                    ? $event->event_payload
                    : json_decode((string) $event->event_payload, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    return false;
                }

                $expected = $this->eventHash(
                    (int) $event->tenant_id,
                    (int) $event->election_id,
                    (string) $event->subject_type,
                    (int) $event->subject_id,
                    (string) $event->event_type,
                    $event->actor_user_id === null ? null : (int) $event->actor_user_id,
                    $this->canonicalize($payload),
                    $event->previous_event_sha256,
                    (string) $event->occurred_at,
                );
            } catch (\Throwable) {
                return false;
            }

            if (!hash_equals((string) $event->event_sha256, $expected)) {
                return false;
            }

            $previousHash = (string) $event->event_sha256;
        }

        return true;
    }

    private function assertInput(
        int $tenantId,
        int $electionId,
        string $subjectType,
        int $subjectId,
        string $eventType,
        ?int $actorUserId,
    ): void {
        if ($tenantId < 1 || $electionId < 1 || $subjectId < 1 || ($actorUserId !== null && $actorUserId < 1)) {
            throw new InvalidArgumentException('Tenant, election, subject and actor identifiers must be positive.');
        }

        if (!isset(self::SUBJECT_TABLES[$subjectType])) {
            throw new InvalidArgumentException('Unsupported election dispute subject type.');
        }

        if (!preg_match('/\A[a-z][a-z0-9_.-]{1,47}\z/D', $eventType)) {
            throw new InvalidArgumentException('Event type must be a stable lowercase identifier of 2–48 characters.');
        }
    }

    private function eventHash(
        int $tenantId,
        int $electionId,
        string $subjectType,
        int $subjectId,
        string $eventType,
        ?int $actorUserId,
        array $payload,
        ?string $previousHash,
        string $occurredAt,
    ): string {
        $material = [
            'schema' => 'arna.election-dispute-event.v1',
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'payload' => $this->canonicalize($payload),
            'previous_event_sha256' => $previousHash,
            'occurred_at' => $occurredAt,
        ];

        return hash('sha256', json_encode(
            $material,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
