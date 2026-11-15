<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services\Receipts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Governance\Security\AgeReceiptSignatureVerifier;
use RuntimeException;
use Throwable;

/**
 * Delivery state machine. The same immutable event and idempotency key are
 * retried; protocol or cryptographic mismatches are quarantined.
 */
final class AgeReceiptOutboxDispatcher
{
    private const MAX_ATTEMPTS = 8;

    public function dispatch(int $outboxId, AgeReceiptGateway $gateway, AgeReceiptSignatureVerifier $verifier): bool
    {
        $event = DB::transaction(function () use ($outboxId): ?object {
            $row = DB::table('age_receipt_outbox')->where('id', $outboxId)->lockForUpdate()->first();
            if ($row === null || in_array($row->delivery_state, ['anchored', 'quarantined', 'permanent_failure'], true)) {
                return null;
            }
            if (!in_array($row->delivery_state, ['pending', 'retryable_failure', 'submitted', 'receipt_received'], true)) {
                return null;
            }
            if ($row->next_attempt_at !== null && Carbon::parse($row->next_attempt_at)->isFuture()) {
                return null;
            }
            DB::table('age_receipt_outbox')->where('id', $outboxId)->update([
                'delivery_state' => 'submitted',
                'attempt_count' => (int) $row->attempt_count + 1,
                'last_error_code' => null,
                'last_error_detail' => null,
                'updated_at' => now(),
            ]);
            return $row;
        });

        if ($event === null) {
            return false;
        }

        try {
            $request = [
                'event' => json_decode((string) $event->canonical_event, true, 512, JSON_THROW_ON_ERROR),
                'event_hash' => (string) $event->event_sha256,
                'idempotency_key' => (string) $event->idempotency_key,
                'expected_previous_sequence' => (int) $event->expected_previous_sequence,
                'expected_previous_receipt_hash' => $event->expected_previous_receipt_sha256,
            ];
            $response = $gateway->append($request);
            $receipt = $response['receipt'] ?? null;
            $witness = $response['witness_checkpoint'] ?? null;
            if (!is_array($receipt) || !is_array($witness)
                || !$verifier->verifyReceipt($receipt)
                || !$verifier->verifyWitnessCheckpoint($witness)) {
                throw new AgeReceiptDeliveryFailure('Receipt or independent witness signature is invalid.', false, true, 'INVALID_SIGNATURE');
            }

            $stream = (string) $event->stream_uuid;
            $sequence = (int) $event->sequence;
            $eventHash = (string) $event->event_sha256;
            $previousHash = $event->expected_previous_receipt_sha256;
            $receiptHash = (string) ($receipt['receipt_hash'] ?? '');
            $witnessReceiptHash = (string) ($witness['receipt_hash'] ?? $witness['witness_receipt_hash'] ?? '');
            if (($receipt['stream_id'] ?? null) !== $stream
                || (int) ($receipt['sequence'] ?? 0) !== $sequence
                || ($receipt['event_hash'] ?? null) !== $eventHash
                || ($receipt['previous_receipt_hash'] ?? null) !== $previousHash
                || !preg_match('/^[a-f0-9]{64}$/D', $receiptHash)
                || ($witness['stream_id'] ?? null) !== $stream
                || (int) ($witness['sequence'] ?? 0) !== $sequence
                || ($witness['event_hash'] ?? null) !== $eventHash
                || $witnessReceiptHash !== $receiptHash
                || ($witness['previous_receipt_hash'] ?? null) !== $previousHash) {
                throw new AgeReceiptDeliveryFailure('Receipt/witness stream, sequence, event or predecessor commitment mismatch.', false, true, 'HEAD_MISMATCH');
            }

            DB::table('age_receipt_outbox')->where('id', $outboxId)->update([
                'delivery_state' => 'anchored',
                'receipt_payload' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'receipt_sha256' => $receiptHash,
                'witness_payload' => json_encode($witness, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'anchored_at' => now(),
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

            return true;
        } catch (AgeReceiptDeliveryFailure $failure) {
            $this->recordFailure($outboxId, $failure->failureCode, $failure->getMessage(), $failure->retryable, $failure->quarantine);
            return false;
        } catch (Throwable $failure) {
            $this->recordFailure($outboxId, 'TRANSPORT_OR_PROTOCOL_ERROR', $failure->getMessage(), true, false);
            return false;
        }
    }

    private function recordFailure(int $id, string $code, string $detail, bool $retryable, bool $quarantine): void
    {
        DB::transaction(function () use ($id, $code, $detail, $retryable, $quarantine): void {
            $row = DB::table('age_receipt_outbox')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->delivery_state === 'anchored') {
                return;
            }
            $attempts = (int) $row->attempt_count;
            $quarantine = $quarantine || !$retryable;
            $state = $quarantine ? 'quarantined' : ($attempts >= self::MAX_ATTEMPTS ? 'permanent_failure' : 'retryable_failure');
            $delay = min(3600, 15 * (2 ** min(8, max(0, $attempts - 1))));
            DB::table('age_receipt_outbox')->where('id', $id)->update([
                'delivery_state' => $state,
                'next_attempt_at' => $state === 'retryable_failure' ? now()->addSeconds($delay) : null,
                'last_error_code' => substr($code, 0, 64),
                'last_error_detail' => substr($detail, 0, 512),
                'quarantined_at' => $quarantine ? now() : null,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Explicitly release a retryable event after operator review. This does not
     * mutate payload, event hash, sequence, predecessor or idempotency key.
     */
    public function scheduleRetry(int $outboxId): void
    {
        DB::transaction(function () use ($outboxId): void {
            $row = DB::table('age_receipt_outbox')->where('id', $outboxId)->lockForUpdate()->first();
            if ($row === null || !in_array($row->delivery_state, ['retryable_failure', 'permanent_failure'], true)) {
                throw new RuntimeException('Only failed, non-quarantined AGE outbox events may be scheduled for retry.');
            }
            if ((int) $row->attempt_count >= self::MAX_ATTEMPTS) {
                throw new RuntimeException('AGE outbox retry limit reached; controlled recovery review is required.');
            }
            DB::table('age_receipt_outbox')->where('id', $outboxId)->update([
                'delivery_state' => 'retryable_failure',
                'next_attempt_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
