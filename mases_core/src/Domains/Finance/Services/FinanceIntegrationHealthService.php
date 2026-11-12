<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceInboxEvent;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceOutboxMessage;

final class FinanceIntegrationHealthService
{
    /** Operational counts only; source reconciliation remains unavailable without adapters. */
    public function snapshot(int $tenantId): array
    {
        $outbox = FinanceOutboxMessage::query()->where('tenant_id', $tenantId);
        $inbox = FinanceInboxEvent::query()->where('tenant_id', $tenantId);

        $outboxCounts = [
            FinanceOutboxMessage::PENDING => (clone $outbox)->where('status', FinanceOutboxMessage::PENDING)->count(),
            FinanceOutboxMessage::PROCESSING => (clone $outbox)->where('status', FinanceOutboxMessage::PROCESSING)->count(),
            FinanceOutboxMessage::DELIVERED => (clone $outbox)->where('status', FinanceOutboxMessage::DELIVERED)->count(),
        ];
        $inboxCounts = [
            FinanceInboxEvent::RECEIVED => (clone $inbox)->where('status', FinanceInboxEvent::RECEIVED)->count(),
            FinanceInboxEvent::PROCESSING => (clone $inbox)->where('status', FinanceInboxEvent::PROCESSING)->count(),
            FinanceInboxEvent::POSTED => (clone $inbox)->where('status', FinanceInboxEvent::POSTED)->count(),
            FinanceInboxEvent::QUARANTINED => (clone $inbox)->where('status', FinanceInboxEvent::QUARANTINED)->count(),
        ];

        return [
            'tenant_id' => $tenantId,
            'captured_at' => now()->toIso8601String(),
            'outbox' => [
                'counts' => $outboxCounts,
                'oldest_pending_at' => (clone $outbox)->where('status', FinanceOutboxMessage::PENDING)->min('created_at'),
            ],
            'inbox' => [
                'counts' => $inboxCounts,
                'oldest_quarantined_at' => (clone $inbox)->where('status', FinanceInboxEvent::QUARANTINED)->min('received_at'),
            ],
            'reconciliation' => [
                'status' => 'not_reconciled',
                'source_control_totals_available' => false,
                'reason' => 'No authoritative source-domain reconciliation adapters or source control totals are registered.',
            ],
        ];
    }
}
