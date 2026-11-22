<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceOutboxMessage;

final class FinanceOutboxDispatcher
{
    /**
     * Atomically lease the oldest available message for one worker.
     * Expired processing leases are reclaimable after a worker crash.
     */
    public function claimNext(int $leaseSeconds = 300): ?FinanceOutboxMessage
    {
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Outbox lease must be between 30 and 3600 seconds.');
        }

        return DB::transaction(function () use ($leaseSeconds): ?FinanceOutboxMessage {
            $now = now();
            $staleBefore = $now->copy()->subSeconds($leaseSeconds);
            $message = FinanceOutboxMessage::query()
                ->where(function ($query) use ($now, $staleBefore): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->where('status', FinanceOutboxMessage::PENDING)
                            ->where('available_at', '<=', $now);
                    })->orWhere(function ($expired) use ($staleBefore): void {
                        $expired->where('status', FinanceOutboxMessage::PROCESSING)
                            ->whereNotNull('claimed_at')
                            ->where('claimed_at', '<=', $staleBefore);
                    });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$message) {
                return null;
            }

            $message->status = FinanceOutboxMessage::PROCESSING;
            $message->attempts = (int) $message->attempts + 1;
            $message->claimed_at = $now;
            $message->claim_token = (string) Str::uuid();
            $message->save();

            return $message->fresh();
        });
    }

    /** Mark delivery complete only for the worker holding the current lease token. */
    public function acknowledge(int $messageId, string $claimToken): void
    {
        DB::transaction(function () use ($messageId, $claimToken): void {
            $message = FinanceOutboxMessage::query()->whereKey($messageId)
                ->where('status', FinanceOutboxMessage::PROCESSING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()->first();

            if (!$message) {
                throw new ConflictHttpException('Outbox lease is missing, expired, or owned by another worker.');
            }

            $message->status = FinanceOutboxMessage::DELIVERED;
            $message->delivered_at = now();
            $message->claimed_at = null;
            $message->claim_token = null;
            $message->last_error = null;
            $message->save();
        });
    }

    /** Release a failed delivery with bounded exponential retry delay. */
    public function releaseForRetry(int $messageId, string $claimToken, string $error): void
    {
        DB::transaction(function () use ($messageId, $claimToken, $error): void {
            $message = FinanceOutboxMessage::query()->whereKey($messageId)
                ->where('status', FinanceOutboxMessage::PROCESSING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()->first();

            if (!$message) {
                throw new ConflictHttpException('Outbox lease is missing, expired, or owned by another worker.');
            }

            $exponent = min(max((int) $message->attempts - 1, 0), 10);
            $delaySeconds = min(3600, 5 * (2 ** $exponent));
            $message->status = FinanceOutboxMessage::PENDING;
            $message->available_at = now()->addSeconds($delaySeconds);
            $message->claimed_at = null;
            $message->claim_token = null;
            $message->last_error = mb_substr(trim(strip_tags($error)), 0, 2000);
            $message->save();
        });
    }
}
