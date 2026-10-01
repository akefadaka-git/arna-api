<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use Throwable;

/**
 * LogLogout
 *
 * Listens for `Illuminate\Auth\Events\Logout` and records each successful
 * logout in the MASES audit trail (`audit_logs` table) via the central
 * `AuditService`.
 *
 * =============================================================================
 * Event source
 * =============================================================================
 * `AuthenticationService::logout()` dispatches the framework's standard
 * `Logout` event:
 *
 *     Event::dispatch(new Logout('api', $user));
 *
 * The framework event is used directly because it carries exactly the
 * information needed (the guard name and the authenticated user model),
 * and because MASES's stateless API contract still resolves the user
 * before dispatching — so no custom event class is required. This is the
 * same pattern used for successful login, where the framework's `Login`
 * event is dispatched by the same service.
 *
 * =============================================================================
 * Relationship to the security_events table
 * =============================================================================
 * Logouts are captured twice, in two different tables with different
 * consumers:
 *
 *   1. `security_events` — written synchronously by `AuthenticationService`
 *      via `logSecurityEvent('logout', [...])`. This is the security view:
 *      "when did this user's session end, and from which IP?" — used by
 *      intrusion-detection and session-audit systems.
 *
 *   2. `audit_logs` — written by THIS listener. This is the audit view:
 *      "what event occurred in the user lifecycle, in what order, with
 *      what correlation ID?" — used by compliance review and forensics.
 *
 * The listener deliberately does NOT duplicate the security-events write,
 * because doing so would produce two `security_events` rows per logout and
 * break any threshold-based alerting that counts logout events.
 *
 * =============================================================================
 * Failure semantics
 * =============================================================================
 * The listener must NEVER crash the logout flow. Any exception raised while
 * writing to the audit trail is caught and written to the application log
 * (via `Log::error`), but is not re-thrown.
 *
 * The authoritative record of a logout already exists in `security_events`,
 * written synchronously by `AuthenticationService` before this listener runs.
 * A failed audit write therefore loses only the audit-trail copy, not the
 * fact that the logout occurred.
 *
 * =============================================================================
 * Request correlation
 * =============================================================================
 * `request_id` is resolved using the same priority order as the canonical
 * error renderer in `bootstrap/app.php` and as the other audit listeners
 * (A6, A7b):
 *
 *   1. `X-Request-ID` header.
 *   2. `X-Correlation-ID` header.
 *   3. A freshly generated UUID v4.
 *
 * Because the same headers are consulted in the same order, an audit entry
 * produced by this listener and an error envelope produced during the same
 * request lifecycle (if the logout flow later raises) will carry the
 * identical `request_id`. This makes cross-table correlation possible.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * This listener is NOT auto-discovered. It lives outside `app/Listeners`,
 * so Laravel's listener auto-discovery does not find it. It is registered
 * explicitly in `CoreServiceProvider::registerEventListeners()` via:
 *
 *     Event::listen(Logout::class, LogLogout::class);
 *
 * See Phase 1 Amendment A9.
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