<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Audit\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use MaaleArna\Arna\Core\Audit\Services\AuditService;
use MaaleArna\Arna\Core\Auth\Models\User;
use Throwable;

/**
 * Writes successful-login audit records; SecurityEventService separately records the security event.
 *
 * @package MaaleArna\Arna\Core\Audit\Listeners
 */
final class LogSuccessfulLogin
{
    /**
     * The audit service used to write the trail entry.
     *
     * Injected via constructor so tests can substitute a fake. Laravel's
     * container resolves `AuditService` from `CoreServiceProvider`'s
     * singleton binding.
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
     * Handle the `Login` event.
     *
     * Called by Laravel's event dispatcher immediately after a successful
     * authentication, whether the login came from this API, a queued job,
     * an artisan command, or the test harness. The listener does not care
     * about the transport — only that a ARNA user authenticated.
     *
     * The event payload (`Illuminate\Auth\Events\Login`) provides:
     *
     *   - $event->guard    : the guard name, e.g. 'api' for Sanctum routes.
     *   - $event->user     : the authenticated user (a `User` model).
     *   - $event->remember : whether the "remember me" flag was set.
     *
     * @param Login $event
     *
     * @return void
     */
    public function handle(Login $event): void
    {
        try {
            $user = $event->user;

            // Defensive: the framework's Login event contract types `$user`
            // as `Authenticatable`, but we only persist an audit entry when
            // it is a full ARNA User model. Anything else (unlikely in
            // production) is skipped silently rather than raising a type
            // error that would obscure the failure in the log.
            if (!$user instanceof User) {
                return;
            }

            // Write the audit entry. The AuditService resolves IP, user
            // agent, and (if not supplied) tenant context from the current
            // request, so we only need to provide what is event-specific.
            $this->auditService->log(
                action: 'auth.login.succeeded',
                data: [
                    'source' => 'event-listener',
                    'event'  => Login::class,
                ],
                user: $user,
                tenantId: $user->default_tenant_id,
                resourceType: 'User',
                resourceId: (string) $user->getKey(),
                newValues: [
                    'guard'    => $event->guard,
                    'remember' => $event->remember,
                    'email'    => $user->email,
                ],
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
                requestId: $this->resolveRequestId(),
            );
        } catch (Throwable $e) {
            // Audit failures must never break login. Log and continue.
            // We deliberately do not re-throw, because doing so would
            // cause a successful authentication to appear as an error to
            // the client, contradicting the security-event record that
            // already exists for the same login.
            Log::error('LogSuccessfulLogin listener failed', [
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
     * This mirrors the resolution order used by the canonical error
     * renderer in `bootstrap/app.php` (`arna_resolve_request_id()`), so
     * audit entries and error responses produced during the same request
     * share the same `request_id`.
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

        // UUID v4 fallback using random_bytes, identical in construction
        // to the fallback used by the canonical renderer.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}