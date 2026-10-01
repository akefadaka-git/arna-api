<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Listeners;

use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Events\FailedLogin;
use MaaleArna\MASES\Core\Auth\Models\User;
use Throwable;

/**
 * LogFailedLogin
 *
 * Listens for `MaaleArna\MASES\Core\Auth\Events\FailedLogin` and records
 * each failed authentication attempt in the MASES audit trail
 * (`audit_logs` table) via the central `AuditService`.
 *
 * =============================================================================
 * Relationship to the security_events table
 * =============================================================================
 * Failed logins are recorded twice, on purpose, in two different tables:
 *
 *   1. `security_events` — written by `SecurityEventService`, called inline
 *      from `AuthenticationService::login()` when the attempt fails. This
 *      is the security view: "was a login attempt made, was it successful,
 *      from which IP, for which account?" — the question that monitoring
 *      and intrusion-detection depend on.
 *
 *   2. `audit_logs` — written by THIS listener. This is the audit view:
 *      "what event occurred, in what order, with what context, across the
 *      full lifecycle of the platform?" — the question that compliance
 *      review and incident forensics depend on.
 *
 * The listener does NOT write to `security_events`. Doing so would produce
 * duplicate security-event rows for the same failure, polluting the
 * security table and breaking any alerting threshold that counts events.
 *
 * =============================================================================
 * Attribution model
 * =============================================================================
 * A failed login has no authenticated user. The `audit_logs.user_id`
 * column is therefore populated from one of two sources, in priority order:
 *
 *   1. `$event->userId` — set by `AuthenticationService` when the attempted
 *      email resolved to a real (but e.g. inactive) account. This is the
 *      useful case for auditors: "user X attempted to log in but their
 *      account was deactivated."
 *
 *   2. `null` — set when the email did not resolve to any account at all.
 *      The audit row is then attributed to the system (no actor). The
 *      attempted email is preserved in `newValues.email` so the row is
 *      still searchable and correlatable.
 *
 * The attempted email is deliberately stored in `newValues.email` rather
 * than in `resource_id`, because `resource_id` is intended to identify a
 * specific resource (e.g. a User row that exists). A failed login against
 * a non-existent email has no resource to point at.
 *
 * =============================================================================
 * Failure semantics
 * =============================================================================
 * The listener must NEVER crash the login flow. Any exception raised while
 * writing to the audit trail is caught and written to the application log
 * (via `Log::error`), but is not re-thrown.
 *
 * The authoritative record of a failed login already exists in
 * `security_events`, written synchronously by `AuthenticationService`
 * before this listener runs. A failed audit write therefore loses only
 * the audit-trail copy of the event, not the fact that the failure
 * occurred. Security monitoring is unaffected.
 *
 * =============================================================================
 * Request correlation
 * =============================================================================
 * `request_id` is resolved using the same priority order as the canonical
 * error renderer in `bootstrap/app.php` and as `LogSuccessfulLogin` (A6):
 *
 *   1. `X-Request-ID` header.
 *   2. `X-Correlation-ID` header.
 *   3. A freshly generated UUID v4.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * This listener is NOT auto-discovered. It lives outside `app/Listeners`,
 * so Laravel's listener auto-discovery does not find it. It is registered
 * explicitly in `CoreServiceProvider::registerEventListeners()` via:
 *
 *     Event::listen(FailedLogin::class, LogFailedLogin::class);
 *
 * See Phase 1 Amendment A9.
 *
 * Until A9 is deployed, this file has no runtime effect. Additionally, the
 * event it listens to (`FailedLogin`, A7a) is not dispatched by
 * `AuthenticationService` until CR-006 is deployed — so even with A9 in
 * place, no invocation would occur until CR-006 completes the chain.
 *
 * @package MaaleArna\MASES\Core\Audit\Listeners
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
            if ($tenantId === null && function_exists('mases_tenant_id')) {
                $tenantId = mases_tenant_id();
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