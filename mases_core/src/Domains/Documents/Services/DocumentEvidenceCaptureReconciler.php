<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conservative reconciliation for durable evidence capture intents.
 *
 * No filesystem deletion occurs until an intent has been claimed under a
 * database row lock, its age has elapsed, and all committed references have
 * been checked. Legacy/untracked objects are deliberately out of scope.
 */
final class DocumentEvidenceCaptureReconciler
{
    public function __construct(private readonly ImmutableDocumentBinaryStore $store) {}

    /** @return array{scanned:int,eligible:int,deleted:int,quarantined:int,failed:int,stale_reserved:int} */
    public function run(int $limit = 100, int $graceMinutes = 1440, bool $apply = false): array
    {
        $limit = max(1, min($limit, 500));
        $graceMinutes = max(60, min($graceMinutes, 43200));
        $cutoff = now()->subMinutes($graceMinutes);

        $candidateIds = DB::table('document_evidence_capture_intents')
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($captured) use ($cutoff): void {
                    $captured->where('status', 'captured')->whereNotNull('captured_at')->where('captured_at', '<=', $cutoff);
                })->orWhere(function ($claimed): void {
                    $claimed->where('status', 'cleanup_claimed')->whereNotNull('cleanup_lease_until')
                        ->where('cleanup_lease_until', '<=', now());
                });
            })
            ->orderBy('id')->limit($limit)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $staleReserved = DB::table('document_evidence_capture_intents')
            ->where('status', 'reserved')->where('reserved_at', '<=', $cutoff)->count();

        $result = [
            'scanned' => count($candidateIds),
            'eligible' => count($candidateIds),
            'deleted' => 0,
            'quarantined' => 0,
            'failed' => 0,
            'stale_reserved' => (int) $staleReserved,
        ];

        if (! $apply) {
            return $result;
        }

        foreach ($candidateIds as $intentId) {
            $claim = DB::transaction(function () use ($intentId): ?object {
                $intent = DB::table('document_evidence_capture_intents')->where('id', $intentId)
                    ->lockForUpdate()->first();
                if ($intent === null) {
                    return null;
                }

                $eligibleCaptured = $intent->status === 'captured'
                    && $intent->captured_at !== null
                    && now()->parse($intent->captured_at)->lte(now()->subDay());
                $expiredClaim = $intent->status === 'cleanup_claimed'
                    && $intent->cleanup_lease_until !== null
                    && now()->parse($intent->cleanup_lease_until)->lte(now());
                if (! $eligibleCaptured && ! $expiredClaim) {
                    return null;
                }

                $reference = DB::table('document_file_versions')
                    ->where('tenant_id', $intent->tenant_id)
                    ->where('file_id', $intent->file_id)
                    ->where('file_version', $intent->file_version)
                    ->first();

                if ($reference !== null) {
                    $matches = (string) $reference->storage_disk === (string) $intent->storage_disk
                        && (string) $reference->storage_path === (string) $intent->storage_path
                        && hash_equals((string) $reference->sha256, (string) $intent->sha256)
                        && (int) $reference->size === (int) $intent->size;
                    if (! $matches) {
                        $this->transition($intent, 'quarantined', 'reference_mismatch');
                    } elseif ($intent->status !== 'linked') {
                        $this->transition($intent, 'linked', 'reference_reconciled');
                    }
                    return null;
                }

                $token = (string) Str::uuid();
                $from = (string) $intent->status;
                DB::table('document_evidence_capture_intents')->where('id', $intentId)->update([
                    'status' => 'cleanup_claimed',
                    'cleanup_claim_token' => $token,
                    'cleanup_claimed_at' => now(),
                    'cleanup_lease_until' => now()->addMinutes(10),
                    'updated_at' => now(),
                ]);
                $this->event((int) $intent->id, (int) $intent->tenant_id, $from, 'cleanup_claimed',
                    $from === 'cleanup_claimed' ? 'cleanup_reclaimed' : 'cleanup_claimed');
                return (object) [
                    'id' => (int) $intent->id,
                    'tenant_id' => (int) $intent->tenant_id,
                    'file_id' => (int) $intent->file_id,
                    'file_version' => (int) $intent->file_version,
                    'disk' => (string) $intent->storage_disk,
                    'path' => (string) $intent->storage_path,
                    'sha256' => (string) $intent->sha256,
                    'size' => (int) $intent->size,
                    'token' => $token,
                ];
            });

            if ($claim === null) {
                continue;
            }

            try {
                $deleted = $this->store->deleteIfMatches(
                    $claim->disk, $claim->tenant_id, $claim->file_id, $claim->file_version,
                    $claim->path, $claim->sha256, $claim->size,
                );
                if (! $deleted) {
                    $this->finishClaim($claim, 'quarantined', 'DELETE_INTEGRITY_MISMATCH');
                    $result['quarantined']++;
                    continue;
                }
                $this->finishClaim($claim, 'deleted', null);
                $result['deleted']++;
            } catch (Throwable) {
                // Keep the claim leased. A later worker retries idempotently
                // after expiry; no exception text or filesystem path is logged.
                DB::table('document_evidence_capture_intents')->where('id', $claim->id)
                    ->where('cleanup_claim_token', $claim->token)->update([
                        'attempt_count' => DB::raw('attempt_count + 1'),
                        'last_error_code' => 'DELETE_FAILED',
                        'updated_at' => now(),
                    ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function finishClaim(object $claim, string $status, ?string $reason): void
    {
        DB::transaction(function () use ($claim, $status, $reason): void {
            $intent = DB::table('document_evidence_capture_intents')->where('tenant_id', $claim->tenant_id)
                ->where('id', $claim->id)->lockForUpdate()->first();
            if ($intent === null || $intent->status !== 'cleanup_claimed'
                || ! hash_equals((string) $intent->cleanup_claim_token, $claim->token)) {
                return;
            }
            $this->transition($intent, $status, $status === 'deleted' ? 'deleted' : 'quarantined', $reason);
        });
    }

    private function transition(object $intent, string $status, string $eventType, ?string $reason = null): void
    {
        $from = (string) $intent->status;
        DB::table('document_evidence_capture_intents')->where('id', $intent->id)->update([
            'status' => $status,
            'cleanup_claim_token' => null,
            'cleanup_lease_until' => null,
            'last_error_code' => $reason,
            'updated_at' => now(),
        ]);
        $this->event((int) $intent->id, (int) $intent->tenant_id, $from, $status, $eventType, $reason);
    }

    private function event(
        int $intentId,
        int $tenantId,
        string $from,
        string $to,
        string $type,
        ?string $reason = null,
    ): void {
        DB::table('document_evidence_capture_events')->insert([
            'capture_intent_id' => $intentId,
            'tenant_id' => $tenantId,
            'from_status' => $from,
            'to_status' => $to,
            'event_type' => $type,
            'actor_user_id' => null,
            'reason_code' => $reason,
            'metadata' => null,
            'occurred_at' => now(),
        ]);
    }
}
