<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Durable control-plane state for filesystem evidence capture.
 *
 * Reservation and capture-state commits are deliberately completed before
 * the workflow submission transaction. The workflow transaction only links
 * an already durable CAPTURED intent to the immutable evidence row.
 */
final class DocumentEvidenceCaptureLifecycle
{
    public function __construct(private readonly ImmutableDocumentBinaryStore $store) {}

    /**
     * @return array{intent_id:int,snapshot:array{disk:string,path:string,sha256:string,size:int}}
     */
    public function prepare(int $tenantId, int $fileId, int $fileVersion, int $actorId): array
    {
        if ($tenantId < 1 || $fileId < 1 || $fileVersion < 1 || $actorId < 1) {
            throw new UnprocessableEntityHttpException('A valid tenant, file version and actor are required for evidence capture.');
        }

        // Lock the File Management row before reading its source path. This
        // serializes concurrent capture reservations for the same source row.
        $reservation = DB::transaction(function () use ($tenantId, $fileId, $fileVersion, $actorId): object {
            $file = DB::table('files')->where('id', $fileId)->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->where('version', $fileVersion)->where('is_public', false)
                ->lockForUpdate()->first(['id', 'version', 'disk', 'path']);
            if ($file === null) {
                throw new UnprocessableEntityHttpException('Submission must bind an existing, non-deleted, private file version owned by the same tenant.');
            }

            $intent = DB::table('document_evidence_capture_intents')
                ->where('tenant_id', $tenantId)->where('file_id', $fileId)->where('file_version', $fileVersion)
                ->lockForUpdate()->first();

            if ($intent === null) {
                $now = now();
                $intentId = (int) DB::table('document_evidence_capture_intents')->insertGetId([
                    'operation_uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'file_id' => $fileId,
                    'file_version' => $fileVersion,
                    'status' => 'reserved',
                    'reserved_by_user_id' => $actorId,
                    'attempt_count' => 0,
                    'reserved_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->event($intentId, $tenantId, null, 'reserved', 'reserved', $actorId);
                $intent = DB::table('document_evidence_capture_intents')->where('id', $intentId)->first();
            }

            if ($intent->status === 'cleanup_claimed') {
                throw new ConflictHttpException('The evidence capture is claimed by cleanup and cannot be attached.');
            }
            if ($intent->status === 'quarantined') {
                throw new ConflictHttpException('The evidence capture is quarantined and requires operator reconciliation.');
            }

            return (object) ['intent' => $intent, 'disk' => (string) $file->disk, 'path' => (string) $file->path];
        });

        $intent = $reservation->intent;
        if ($intent->status === 'linked') {
            $existing = DB::table('document_file_versions')->where('tenant_id', $tenantId)
                ->where('file_id', $fileId)->where('file_version', $fileVersion)->first();
            if ($existing === null || ! $this->store->verify(
                (string) $existing->storage_disk,
                (string) $existing->storage_path,
                (string) $existing->sha256,
                (int) $existing->size,
            )) {
                throw new UnprocessableEntityHttpException('The previously linked immutable evidence object is missing or has changed.');
            }

            return [
                'intent_id' => (int) $intent->id,
                'snapshot' => [
                    'disk' => (string) $existing->storage_disk,
                    'path' => (string) $existing->storage_path,
                    'sha256' => (string) $existing->sha256,
                    'size' => (int) $existing->size,
                ],
            ];
        }

        if ($intent->status === 'captured') {
            $snapshot = [
                'disk' => (string) $intent->storage_disk,
                'path' => (string) $intent->storage_path,
                'sha256' => (string) $intent->sha256,
                'size' => (int) $intent->size,
            ];
            if ($snapshot['disk'] === '' || $snapshot['path'] === ''
                || ! $this->store->verify($snapshot['disk'], $snapshot['path'], $snapshot['sha256'], $snapshot['size'])) {
                $this->quarantine((int) $intent->id, $tenantId, $actorId, 'CAPTURE_INTEGRITY_MISMATCH');
                throw new UnprocessableEntityHttpException('The captured immutable evidence failed its integrity check and was quarantined.');
            }

            return ['intent_id' => (int) $intent->id, 'snapshot' => $snapshot];
        }

        if ($intent->status !== 'reserved') {
            throw new ConflictHttpException('The evidence capture is not in an attachable lifecycle state.');
        }

        try {
            $snapshot = $this->store->capture($tenantId, $fileId, $fileVersion, $reservation->disk, $reservation->path);
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($intent, $tenantId, $actorId): void {
                $locked = DB::table('document_evidence_capture_intents')->where('tenant_id', $tenantId)
                    ->where('id', $intent->id)->lockForUpdate()->first();
                if ($locked !== null && $locked->status === 'reserved') {
                    DB::table('document_evidence_capture_intents')->where('id', $intent->id)->update([
                        'attempt_count' => DB::raw('attempt_count + 1'),
                        'last_error_code' => 'CAPTURE_FAILED',
                        'updated_at' => now(),
                    ]);
                    $this->event((int) $intent->id, $tenantId, 'reserved', 'reserved', 'capture_failed', $actorId);
                }
            });
            throw $exception;
        }

        DB::transaction(function () use ($intent, $tenantId, $actorId, $snapshot): void {
            $locked = DB::table('document_evidence_capture_intents')->where('tenant_id', $tenantId)
                ->where('id', $intent->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, ['reserved', 'captured'], true)) {
                throw new ConflictHttpException('The capture intent changed state while its immutable object was being written.');
            }

            if ($locked->status === 'reserved') {
                DB::table('document_evidence_capture_intents')->where('id', $intent->id)->update([
                    'status' => 'captured',
                    'storage_disk' => $snapshot['disk'],
                    'storage_path' => $snapshot['path'],
                    'sha256' => $snapshot['sha256'],
                    'size' => $snapshot['size'],
                    'captured_at' => now(),
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);
                $this->event((int) $intent->id, $tenantId, 'reserved', 'captured', 'captured', $actorId, [
                    'sha256' => $snapshot['sha256'],
                    'size' => $snapshot['size'],
                ]);
            } elseif (! hash_equals((string) $locked->sha256, $snapshot['sha256'])
                || (string) $locked->storage_path !== $snapshot['path']
                || (string) $locked->storage_disk !== $snapshot['disk']) {
                throw new ConflictHttpException('A retry produced different bytes for an already captured File Management version.');
            }
        });

        return ['intent_id' => (int) $intent->id, 'snapshot' => $snapshot];
    }

    /**
     * Must be called inside the same transaction that inserts/validates the
     * immutable evidence row and attaches it to the workflow aggregate.
     */
    public function markLinked(int $intentId, int $tenantId, int $evidenceVersionId, int $actorId): void
    {
        $intent = DB::table('document_evidence_capture_intents')->where('tenant_id', $tenantId)
            ->where('id', $intentId)->lockForUpdate()->first();
        if ($intent === null || ! in_array($intent->status, ['captured', 'linked'], true)) {
            throw new ConflictHttpException('The evidence capture is no longer available for workflow attachment.');
        }

        if ($intent->status === 'captured') {
            DB::table('document_evidence_capture_intents')->where('id', $intentId)->update([
                'status' => 'linked',
                'linked_at' => now(),
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
            $this->event($intentId, $tenantId, 'captured', 'linked', 'linked', $actorId, [
                'evidence_version_id' => $evidenceVersionId,
            ]);
        }
    }

    public function quarantine(int $intentId, int $tenantId, int $actorId, string $reasonCode): void
    {
        DB::transaction(function () use ($intentId, $tenantId, $actorId, $reasonCode): void {
            $intent = DB::table('document_evidence_capture_intents')->where('tenant_id', $tenantId)
                ->where('id', $intentId)->lockForUpdate()->first();
            if ($intent === null || in_array($intent->status, ['linked', 'deleted', 'quarantined'], true)) {
                return;
            }
            $from = (string) $intent->status;
            DB::table('document_evidence_capture_intents')->where('id', $intentId)->update([
                'status' => 'quarantined',
                'last_error_code' => $reasonCode,
                'updated_at' => now(),
            ]);
            $this->event($intentId, $tenantId, $from, 'quarantined', 'quarantined', $actorId, ['reason_code' => $reasonCode]);
        });
    }

    /** @param array<string, mixed>|null $metadata */
    private function event(
        int $intentId,
        int $tenantId,
        ?string $from,
        string $to,
        string $type,
        ?int $actorId,
        ?array $metadata = null,
    ): void {
        DB::table('document_evidence_capture_events')->insert([
            'capture_intent_id' => $intentId,
            'tenant_id' => $tenantId,
            'from_status' => $from,
            'to_status' => $to,
            'event_type' => $type,
            'actor_user_id' => $actorId,
            'reason_code' => $type === 'capture_failed' ? 'CAPTURE_FAILED' : null,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
