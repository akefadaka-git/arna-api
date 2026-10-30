<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAuditEvent;

final class OrganizationAuditService
{
    public function record(
        int $tenantId,
        Request $request,
        string $subjectType,
        int $subjectId,
        string $action,
        string $reason,
        ?array $before,
        ?array $after,
    ): OrganizationAuditEvent {
        return OrganizationAuditEvent::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'request_id' => $request->header('X-Request-ID'),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function paginateForTenant(int $tenantId, array $filters): LengthAwarePaginator
    {
        return OrganizationAuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->when(isset($filters['subject_type']), fn ($q) => $q->where('subject_type', $filters['subject_type']))
            ->when(isset($filters['subject_id']), fn ($q) => $q->where('subject_id', $filters['subject_id']))
            ->when(isset($filters['action']), fn ($q) => $q->where('action', $filters['action']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }
}
