<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Audit\Listeners;

use Illuminate\Support\Facades\Log;
use MaaleArna\Arna\Core\Audit\Services\AuditService;
use MaaleArna\Arna\Core\Auth\Events\FailedLogin;
use MaaleArna\Arna\Core\Auth\Models\User;
use Throwable;

/**
 * Writes failed-login audit records; SecurityEventService separately records the security event.
 *
 * @package MaaleArna\Arna\Core\Audit\Listeners
 */
final class LogFailedLogin
{
    /**
     * The audit service used to write the trail entry.
     *
     * @var AuditService
     */
    private AuditService $auditService;

    /**
     * Create a new listener instance.
     *
     * @param AuditService $auditService
     */
    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Handle the `FailedLogin` event.
     *
     * Called by Laravel's event dispatcher when `AuthenticationService`
     * dispatches the event, immediately before it raises its
     * `AuthenticationException`. The order matters: the listener runs to
     * completion (or fails safely) before the exception reaches the
     * canonical renderer.
     *
     * @param FailedLogin $event
     *
     * @return void
     */
    public function handle(FailedLogin $event): void
    {
        try {
            // Resolve the actor (may be null). We only pass a User model
            // when the event carries a resolved user ID, and we only load
            // it if we actually need to — loading is a DB round trip.
            $user = null;
            if ($event->userId !== null) {
                $user = User::find($event->userId);
            }

            // Resolve the tenant context. The event's tenant is authoritative
            // because it records what the caller was attempting to authenticate
            // against, which may differ from the request's resolved tenant.
            $tenantId = $event->tenantId;
            if ($tenantId === null && function_exists('arna_tenant_id')) {
                $tenantId = arna_tenant_id();
            }

            // Write the audit entry.
            $this->auditService->log(
                action: 'auth.login.failed',
                data: [
                    'source' => 'event-listener',
                    'event'  => FailedLogin::class,
                    'reason' => $event->reason,
                    'guard'  => $event->guard,
                ],
                user: $user,
                tenantId: $tenantId,
                resourceType: 'User',
                resourceId: $event->userId !== null ? (string) $event->userId : null,
                newValues: [
                    'email'  => $event->email,
                    'reason' => $event->reason,
                    'guard'  => $event->guard,
                ],
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
                requestId: $this->resolveRequestId(),
            );
        } catch (Throwable $e) {
            // Audit failures must never surface to the client as anything
            // other than the original authentication failure. Log and
            // continue; do not re-throw.
            Log::error('LogFailedLogin listener failed', [
                'error'   => $e->getMessage(),
                'email'   => $event->email,
                'user_id' => $event->userId,
                'reason'  => $event->reason,
            ]);
        }
    }

    /**
     * Resolve the request ID for the current request, if available.
     *
     * Priority:
     *   1. `X-Request-ID` header (set by upstream proxies / clients).
     *   2. `X-Correlation-ID` header (alternative common name).
     *   3. A freshly generated UUID v4.
     *
     * Mirrors `LogSuccessfulLogin::resolveRequestId()` and the canonical
     * renderer in `bootstrap/app.php`.
     *
     * @return string
     */
    private function resolveRequestId(): string
    {
        $request = request();

        foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}