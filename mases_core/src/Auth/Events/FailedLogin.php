<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * FailedLogin
 *
 * Domain event dispatched when a login attempt fails in the MASES platform.
 *
 * =============================================================================
 * Why this event exists
 * =============================================================================
 * Laravel ships with `Illuminate\Auth\Events\Failed`, but that event is only
 * dispatched by `Auth::attempt()`. MASES deliberately does not use
 * `Auth::attempt()` — see the `AuthenticationService` class docblock, section
 * "Stateless API login" — because it would create a session that survives
 * token revocation.
 *
 * As a result, Laravel's `Failed` event never fires in this codebase, and a
 * listener attached to it would be dead code. This event fills that gap.
 *
 * The event is dispatched by `AuthenticationService::login()` immediately
 * before it raises its `AuthenticationException`. It carries enough
 * information for listeners to write an audit entry, update security
 * counters, and trigger alerting without needing to inspect the exception
 * or reconstruct the request context.
 *
 * =============================================================================
 * Design notes
 * =============================================================================
 * 1. This event is NOT dispatched when the credentials are correct but the
 *    user is inactive. That case is currently reported by the same exception
 *    (`AUTH_INVALID_CREDENTIALS`), but it will be dispatched with a
 *    different `reason` value (`inactive_account`) so downstream consumers
 *    can distinguish it from a truly wrong password.
 *
 * 2. `$userId` is nullable. Failed logins usually have no resolved user —
 *    the email did not match any active account. When the email DOES
 *    resolve to a real user (e.g. an inactive account), the service
 *    populates this field so auditors can group failures by user ID.
 *
 * 3. `$reason` is a short machine-readable code. Suggested values:
 *
 *      'invalid_credentials'   — email unknown OR password mismatch.
 *      'inactive_account'      — user exists but `is_active` is false.
 *      'tenant_mismatch'       — user exists but is not a member of the
 *                                tenant they attempted to log in to.
 *      'account_locked'        — reserved for future lockout enforcement.
 *
 *    Adding new reasons is safe; consumers should treat unknown reasons
 *    as equivalent to 'invalid_credentials' for alerting thresholds.
 *
 * 4. `$email` is stored in plaintext on the event. This is intentional:
 *    failed-login auditing is precisely the case where knowing "which
 *    identifier was attempted" matters, and the value came from the
 *    request in the first place. It is not treated as sensitive, because
 *    the attacker already knew it — they sent it.
 *
 * 5. `$tenantId` records the tenant context that the caller was attempting
 *    to authenticate against — not necessarily the tenant they belong to.
 *    This lets auditors answer "which tenant was targeted?" even for
 *    attackers who belong to no tenant at all.
 *
 * 6. This event carries only primitives. It does NOT carry the `User` model
 *    (there may not be one), nor the password (never), nor the request
 *    object (listeners can access it via `request()` if they need IP or
 *    user agent).
 *
 * =============================================================================
 * Consumers
 * =============================================================================
 *   - `LogFailedLogin` listener (A7b) — writes to `audit_logs`.
 *   - `SecurityEventService::logLoginFailed()` — called directly by the
 *     service, NOT via this event, because security events are the
 *     authoritative record and must be written even if no listeners are
 *     registered. The listener is the audit-trail copy; the service call
 *     is the security copy.
 *
 * @package MaaleArna\MASES\Core\Auth\Events
 */
final class FailedLogin
{
    use Dispatchable;

    /**
     * The email address that was attempted.
     *
     * Always populated. This is the primary identifier used to correlate
     * failed-login attempts across audit entries.
     *
     * @var string
     */
    public readonly string $email;

    /**
     * The reason code for the failure.
     *
     * Machine-readable. See the class docblock for the suggested values.
     * Consumers should treat unrecognised values conservatively.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * The guard that handled the attempt.
     *
     * Always 'api' in MASES — the platform is stateless and uses Sanctum
     * bearer tokens exclusively. Included for forward-compatibility with
     * any future secondary guard (e.g. an administrative web guard).
     *
     * @var string
     */
    public readonly string $guard;

    /**
     * The resolved user ID, if the email matched a real account.
     *
     * Null when the email did not resolve to any user (the common case).
     * Populated when the email matched an inactive account or a user who
     * failed tenant membership — situations where the failure mode is
     * still "login denied", but the identity is known to the system.
     *
     * @var int|null
     */
    public readonly ?int $userId;

    /**
     * The tenant context that the caller was attempting to authenticate
     * against, if any.
     *
     * Null when the caller did not specify a tenant.
     *
     * @var int|null
     */
    public readonly ?int $tenantId;

    /**
     * Create a new failed-login event instance.
     *
     * @param string   $email    The attempted email address.
     * @param string   $reason   Machine-readable reason code.
     * @param string   $guard    The guard name. Defaults to 'api'.
     * @param int|null $userId   The resolved user ID, if known.
     * @param int|null $tenantId The attempted tenant context, if any.
     */
    public function __construct(
        string $email,
        string $reason = 'invalid_credentials',
        string $guard = 'api',
        ?int $userId = null,
        ?int $tenantId = null,
    ) {
        $this->email    = $email;
        $this->reason   = $reason;
        $this->guard    = $guard;
        $this->userId   = $userId;
        $this->tenantId = $tenantId;
    }
}