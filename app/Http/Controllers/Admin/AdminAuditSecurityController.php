<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;\nuse Carbon\Carbon;\nuse RuntimeException;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;

final class AdminAuditSecurityController extends Controller
{
    public function auditLogs(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:100'],
            'resource_type' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::query()
            ->with(['user:id,email', 'tenant:id,name_en,name_am'])
            ->where('tenant_id', $tenantId)
            ->when($filters['q'] ?? null, function (Builder $builder, string $term): void {
                $builder->where(function (Builder $nested) use ($term): void {
                    $like = '%' . addcslashes($term, '%_\\') . '%';
                    $nested->where('action', 'like', $like)
                        ->orWhere('resource_type', 'like', $like)
                        ->orWhere('resource_id', 'like', $like)
                        ->orWhere('request_id', 'like', $like);
                });
            })
            ->when($filters['action'] ?? null, fn (Builder $q, string $value) => $q->where('action', $value))
            ->when($filters['resource_type'] ?? null, fn (Builder $q, string $value) => $q->where('resource_type', $value))
            ->when($filters['actor_id'] ?? null, fn (Builder $q, int $value) => $q->where('user_id', $value))
            ->when($filters['from'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '<=', $value));

        $page = $query->latest('id')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(fn (AuditLog $entry): array => $this->auditPayload($entry));

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function auditLog(Request $request, int $id): JsonResponse
    {
        $entry = AuditLog::query()
            ->with(['user:id,email', 'tenant:id,name_en,name_am'])
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->auditPayload($entry)]);
    }

    public function securityEvents(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'in:info,warning,critical'],
            'status' => ['nullable', 'in:open,resolved'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'ip_address' => ['nullable', 'ip'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SecurityEvent::query()
            ->with(['user:id,email', 'resolver:id,email'])
            ->where('tenant_id', $tenantId)
            ->when($filters['q'] ?? null, function (Builder $builder, string $term): void {
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $builder->where(function (Builder $nested) use ($like): void {
                    $nested->where('event_type', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('request_id', 'like', $like);
                });
            })
            ->when($filters['event_type'] ?? null, fn (Builder $q, string $value) => $q->where('event_type', $value))
            ->when($filters['severity'] ?? null, fn (Builder $q, string $value) => $q->where('severity', $value))
            ->when(($filters['status'] ?? null) === 'open', fn (Builder $q) => $q->whereNull('resolved_at'))
            ->when(($filters['status'] ?? null) === 'resolved', fn (Builder $q) => $q->whereNotNull('resolved_at'))
            ->when($filters['actor_id'] ?? null, fn (Builder $q, int $value) => $q->where('user_id', $value))
            ->when($filters['ip_address'] ?? null, fn (Builder $q, string $value) => $q->where('ip_address', $value))
            ->when($filters['from'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '<=', $value));

        $page = $query->latest('id')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(fn (SecurityEvent $event): array => $this->securityPayload($event));

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function securityEvent(Request $request, int $id): JsonResponse
    {
        $event = SecurityEvent::query()
            ->with(['user:id,email', 'resolver:id,email'])
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->securityPayload($event)]);
    }

    public function resolveSecurityEvent(Request $request, int $id, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:10', 'max:4000'],
        ]);
        $tenantId = $this->tenantId();
        $actor = $request->user();

        $event = DB::transaction(function () use ($id, $tenantId, $actor, $validated, $audit): SecurityEvent {
            $event = SecurityEvent::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($event->resolved_at !== null) {
                throw ValidationException::withMessages([
                    'status' => 'This security event has already been resolved.',
                ]);
            }

            $before = [
                'resolved_at' => null,
                'resolved_by' => null,
                'resolution_notes' => null,
            ];

            $event->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'resolution_notes' => $validated['resolution_notes'],
            ])->save();

            $auditEntry = $audit->log(
                action: 'security.event.resolved',
                user: $actor,
                tenantId: $tenantId,
                resourceType: 'security_event',
                resourceId: (string) $event->id,
                oldValues: $before,
                newValues: [
                    'resolved_at' => $event->resolved_at?->toIso8601String(),
                    'resolved_by' => $actor->id,
                    'resolution_notes' => $validated['resolution_notes'],
                ],
            );
            if ($auditEntry === null) {
                throw new RuntimeException('The security-event resolution audit record could not be persisted.');
            }

            return $event->fresh(['user:id,email', 'resolver:id,email']);
        });

        return response()->json(['success' => true, 'data' => $this->securityPayload($event)]);
    }

    /**
     * Redact credential-shaped keys recursively before exposing forensic data.
     *
     * @param array<string, mixed>|null $value
     * @return array<string, mixed>|null
     */
    private function redact(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $sensitive = [
            'password', 'password_confirmation', 'current_password', 'token',
            'access_token', 'bearer_token', 'api_token', 'session_token',
            'password_reset_token', 'email_verification_token', 'refresh_token',
            'secret', 'api_key', 'api_secret', 'client_secret', 'mfa_secret',
            'totp_secret', 'two_factor_secret', 'two_factor_recovery_codes',
            'otp', 'otp_code', 'one_time_password', 'one_time_code', 'totp',
            'totp_code', 'mfa_code', 'verification_code',
        ];

        $clean = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $clean[$key] = $this->redact($item);
            } else {
                $clean[$key] = $item;
            }
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private function auditPayload(AuditLog $entry): array
    {
        return array_merge($entry->toArray(), [
            'old_values' => $this->redact($entry->old_values),
            'new_values' => $this->redact($entry->new_values),
            'metadata' => $this->redact($entry->metadata),
        ]);
    }

    /** @return array<string, mixed> */
    private function securityPayload(SecurityEvent $event): array
    {
        return array_merge($event->toArray(), [
            'event_data' => $this->redact($event->event_data),
        ]);
    }

    private function tenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if($tenantId === null, 403, 'A verified tenant context is required.');

        return (int) $tenantId;
    }
}
