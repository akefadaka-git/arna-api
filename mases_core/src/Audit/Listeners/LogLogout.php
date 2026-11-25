<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use Throwable;

/**
 * Writes logout audit records through AuditService from the framework Logout event.
 *
 * @package MaaleArna\MASES\Core\Audit\Listeners
 */
final class LogLogout
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
     * Handle the `Logout` event.
     *
     * Called by Laravel's event dispatcher immediately after
     * `AuthenticationService::logout()` completes the token revocation
     * steps and dispatches the framework event. The listener does not care
     * which transport initiated the logout — it only records the fact.
     *
     * The event payload (`Illuminate\Auth\Events\Logout`) provides:
     *
     *   - $event->guard : the guard name, e.g. 'api' for Sanctum routes.
     *   - $event->user  : the authenticated user (a `User` model).
     *
     * @param Logout $event
     *
     * @return void
     */
    public function handle(Logout $event): void
    {
        try {
            $user = $event->user;

            // Defensive: the framework's Logout event contract types `$user`
            // as `Authenticatable`, but we only persist an audit entry when
            // it is a full MASES User model. Anything else (unlikely in
            // production) is skipped silently rather than raising a type
            // error that would obscure the failure in the log.
            if (!$user instanceof User) {
                return;
            }

            // Resolve tenant context. The user's default tenant is the
            // authoritative context for a logout, because logout is a
            // user-scoped operation, not a request-scoped one. If the user
            // has no default tenant, fall back to the request's resolved
            // tenant, which may also be null.
            $tenantId = $user->default_tenant_id;
            if ($tenantId === null && function_exists('mases_tenant_id')) {
                $tenantId = mases_tenant_id();
            }

            // Write the audit entry.
            $this->auditService->log(
                action: 'auth.logout',
                data: [
                    'source' => 'event-listener',
                    'event'  => Logout::class,
                    'guard'  => $event->guard,
                ],
                user: $user,
                tenantId: $tenantId,
                resourceType: 'User',
                resourceId: (string) $user->getKey(),
                newValues: [
                    'guard' => $event->guard,
                    'email' => $user->email,
                ],
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
                requestId: $this->resolveRequestId(),
            );
        } catch (Throwable $e) {
            // Audit failures must never surface to the client as anything
            // other than the original logout success. Log and continue; do
            // not re-throw.
            Log::error('LogLogout listener failed', [
                'error'   => $e->getMessage(),
                'user_id' => $event->user?->getKey(),
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
     * Mirrors the resolution order of `LogSuccessfulLogin` (A6),
     * `LogFailedLogin` (A7b), and the canonical renderer in
     * `bootstrap/app.php`.
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