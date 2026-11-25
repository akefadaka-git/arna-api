<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use Carbon\\Carbon;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use MaaleArna\\MASES\\Core\\Audit\\Models\\AuditLog;
use MaaleArna\\MASES\\Core\\Security\\Models\\SecurityEvent;

/**
 * Platform-level, read-only audit and security oversight.
 *
 * This surface is deliberately restricted to records without a tenant
 * context. Tenant-scoped investigations remain on the tenant-bound
 * AdminAuditSecurityController and are not widened by this controller.
 */
final class PlatformAuditSecurityController extends Controller
{
    public function auditLogs(Request $request): JsonResponse
    {
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
            ->with('user:id,email')
            ->whereNull('tenant_id')
            ->when($filters['q'] ?? null, function (Builder $builder, string $term): void {
                $like = '%' . addcslashes($term, '%_\\\\') . '%';
                $builder->where(function (Builder $nested) use ($like): void {
                    $nested->where('action', 'like', $like)
                        ->orWhere('resource_type', 'like', $like)
                        ->orWhere('resource_id', 'like', $like)
                        ->orWhere('request_id', 'like', $like);
                });
            })
            ->when($filters['action'] ?? null, fn (Builder $q, string $value) => $q->where('action', $value))
            ->when($filters['resource_type'] ?? null, fn (Builder $q, string $value) => $q->where('resource_type', $value))
            ->when($filters['actor_id'] ?? null, fn (Builder $q, int $value) => $q->where('user_id', $value))
            ->when($filters['from'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '>=', Carbon::parse($value)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '<=', Carbon::parse($value)->endOfDay()));

        $page = $query->latest('id')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(static function (AuditLog $entry): array {
            $payload = $entry->toArray();
            $payload['old_values'] = self::redact($entry->old_values);
            $payload['new_values'] = self::redact($entry->new_values);
            $payload['metadata'] = self::redact($entry->metadata);
            return $payload;
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function securityEvents(Request $request): JsonResponse
    {
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
            ->whereNull('tenant_id')
            ->when($filters['q'] ?? null, function (Builder $builder, string $term): void {
                $like = '%' . addcslashes($term, '%_\\\\') . '%';
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
        $page->getCollection()->transform(static function (SecurityEvent $event): array {
            $payload = $event->toArray();
            $payload['event_data'] = self::redact($event->event_data);
            return $payload;
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    /** @param array<string, mixed>|null $value */
    private static function redact(?array $value): ?array
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
                $clean[$key] = self::redact($item);
            } else {
                $clean[$key] = $item;
            }
        }

        return $clean;
    }
}
