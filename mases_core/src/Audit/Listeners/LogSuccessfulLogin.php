<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use Throwable;

/**
 * LogSuccessfulLogin
 *
 * Listens for `Illuminate\Auth\Events\Login` and records the event in the
 * MASES audit trail (`audit_logs` table) via the central `AuditService`.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * Successful logins are captured in TWO distinct places, each serving a
 * different purpose:
 *
 *   1. `security_events` table — written by `SecurityEventService`, called
 *      inline from `AuthenticationService::login()`. This is the security
 *      view of the event: "was there a successful login for this user in
 *      this tenant, at this time, from this IP?" — the question that
 *      security monitoring and alerting depend on.
 *
 *   2. `audit_logs` table — written by THIS listener via `AuditService`.
 *      This is the audit view of the event: "who did what, when, and to
 *      which resource?" — the question that compliance review and forensic
 *      analysis depend on.
 *
 * These views are complementary, not duplicative:
 *
 *   - Different tables with different schemas.
 *   - Different retention requirements (security events typically shorter,
 *     audit logs longer).
 *   - Different query patterns and consumers.
 *   - Removing one would leave a gap in either security monitoring or
 *     compliance auditing.
 *
 * This listener deliberately does NOT write to `security_events`. That
 * responsibility already belongs to `AuthenticationService`, and calling
 * `SecurityEventService` here would produce two security-event rows per
 * successful login.
 *
 * =============================================================================
 * Failure semantics
 * =============================================================================
 * The listener must NEVER crash the login flow. Any exception raised while
 * writing to the audit trail is caught and written to the application log
 * (via `Log::error`), but is not re-thrown. Authentication must succeed
 * for the user even if the audit trail is temporarily unavailable.
 *
 * This "fail open on audit" policy is deliberate. The authoritative record
 * of a successful login already exists in `security_events`, written before
 * this listener runs. A failed audit write therefore loses only the
 * audit-trail copy of the event, not the fact that the login occurred.
 * Security and compliance reviewers still see the login via the security
 * event; the audit trail simply lags until the next successful write.
 *
 * =============================================================================
 * Request correlation
 * =============================================================================
 * The listener resolves a `request_id` for each entry using the same
 * priority order as the canonical error renderer in `bootstrap/app.php`:
 *
 *   1. `X-Request-ID` header (set by upstream proxies / clients).
 *   2. `X-Correlation-ID` header (alternative common name).
 *   3. A freshly generated UUID v4.
 *
 * Because both the renderer and this listener resolve from the same headers
 * in the same order, an audit entry and an error envelope produced during
 * the same request lifecycle will carry the identical `request_id`. That
 * makes it possible to correlate audit entries with API responses when
 * debugging incidents.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * This listener is NOT auto-discovered. It lives outside `app/Listeners`,
 * so Laravel's listener auto-discovery does not find it. It is registered
 * explicitly in `CoreServiceProvider::registerEventListeners()` via:
 *
 *     Event::listen(Login::class, LogSuccessfulLogin::class);
 *
 * See Phase 1 Amendment A9.
 *
 * Until A9 is deployed, this file has no runtime effect — the class exists
 * but is never invoked. This is intentional: the file can be uploaded and
 * verified independently of the provider change, and the provider change
 * is delivered in A9 after A6/A7/A8 are all in place.
 *
 * @package MaaleArna\MASES\Core\Audit\Listeners
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
     * about the transport — only that a MASES user authenticated.
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
            // it is a full MASES User model. Anything else (unlikely in
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
     * renderer in `bootstrap/app.php` (`mases_resolve_request_id()`), so
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