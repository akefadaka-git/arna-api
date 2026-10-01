<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Security\Services;

use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Carbon\Carbon;

/**
 * SecurityEventService
 *
 * Central service for logging security‑related events to the database.
 * Records events such as login attempts, password resets, MFA events,
 * suspicious activity, and other security‑critical occurrences.
 *
 * All events are stored in the `security_events` table with appropriate
 * severity levels and context data for monitoring and alerting.
 *
 * **Important:** Security events are **always** logged, regardless of the
 * `audit_enabled` feature flag. The audit flag only controls non‑security
 * audit logs (e.g., file uploads, data changes). This separation ensures
 * that critical security traces are never lost.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Tenant resolution order: explicit `$data['tenant_id']`
 *          → `mases_tenant_id()` → `Request::has('tenant_id')` fallback reading
 *          the request body/query.
 *
 * 1.0.1  — C-09 correction. The `Request::has('tenant_id')` fallback is
 *          removed. Security events now record the tenant resolved by the
 *          middleware pipeline (via `mases_tenant_id()` in HTTP context) or
 *          the fallback chain in non-HTTP contexts — never a client-supplied
 *          request value.
 *
 *          WHY THIS MATTERS
 *          -----------------
 *          The security event log is part of the evidence trail. Under the
 *          pre-C-09 resolution order, a request that carried `X-Tenant-ID: A`
 *          (verified by the middleware chain) but `tenant_id=B` in the body
 *          would produce a `security_events` row whose `tenant_id` column
 *          was `B`. That row is not merely misleading — it is the wrong
 *          tenant recorded against a security incident. Auditing,
 *          anomaly detection, and compliance review all depend on the
 *          tenant column being the true tenant of the request.
 *
 *          The C-09 design establishes `TenantContextService` as the single
 *          authoritative source of tenant context for HTTP requests. The
 *          `mases_tenant_id()` helper now reads exclusively from that
 *          service in HTTP context. This file therefore removes its own
 *          redundant body read and relies on the corrected helper.
 *
 *          The `$data['tenant_id']` parameter remains supported for callers
 *          that pass an explicit tenant (e.g. an audit listener processing
 *          an event whose tenant is known from the event payload). That is
 *          legitimate: the caller is authoritative for the value it passes.
 *          What is removed is only the fallback to attacker-influenced
 *          request input.
 *
 *          No API change. No behavioral change for any caller that supplied
 *          an explicit `$data['tenant_id']`. No behavioral change in
 *          non-HTTP contexts. The only change is when the tenant is resolved
 *          from `mases_tenant_id()` in HTTP context — the value is now the
 *          middleware-verified tenant.
 *
 * 1.0.2  — CR-3 correction. The key `'authorization'` is removed from the
 *          `$sensitiveKeys` allow-block list used by `buildEventData()`.
 *
 *          WHY THIS MATTERS
 *          -----------------
 *          The name `'authorization'` was placed on the sensitive list to
 *          defend against a leaked HTTP `Authorization:` request header.
 *          A request header with that name is not, however, what arrives
 *          at this service — headers are never passed into `$data`. What
 *          does arrive under the key `'authorization'` is the *structured
 *          denial payload* produced by
 *          `MaaleArna\MASES\Core\Listeners\LogAuthorizationFailureListener`,
 *          which in turn calls
 *          `AuthorizationFailureEvent::toSecurityEventData()`.
 *
 *          That structured payload carries only:
 *
 *              check_type     — 'permission' | 'role' | 'application'
 *              check_value    — the slug that was required
 *              application    — application slug, or null
 *              resource_type  — inferred resource, or null
 *              resource_id    — always null at present
 *              action         — inferred verb, or null
 *              occurred_at    — ISO-8601 timestamp
 *
 *          None of those values are credentials, tokens, or secrets. They
 *          are the audit record of *what check was denied* — precisely the
 *          information the compliance trail needs to be useful.
 *
 *          Under v1.0.1, the presence of `'authorization'` on the sensitive
 *          list caused the entire nested payload to be stripped before
 *          persistence. The `security_events.event_data` column therefore
 *          contained no `authorization` key at all, and downstream tests
 *          (`AuthorizationFailureEventTest::denied_event_data_contains_structured_authorization_context`
 *          and `::denied_event_data_infers_action_and_resource_from_role_denial_as_null`)
 *          could not assert on the structured denial context. The auditor
 *          lost the *reason* for the denial, retaining only the fact of it.
 *
 *          Removing the key from the sensitive list restores the structured
 *          payload to the persisted event data. Security posture is not
 *          weakened: the sensitive filter continues to strip the eight
 *          credential-shaped keys that can actually leak secrets
 *          (`password`, `password_confirmation`, `token`, `api_key`,
 *          `secret`, `refresh_token`, `current_password`) plus any
 *          `User`/`Tenant` object instances and any object without a
 *          `toArray()` method.
 *
 *          No API change. No signature change. No change to the public
 *          contract "security events are always logged". The only change
 *          is the removal of one entry from a private filter list.
 *
 * 1.0.3  — D-14 / H-19. Sensitive-key filter hardened.
 *
 *          THREE CHANGES, ALL CONFINED TO THE FILTER
 *          ------------------------------------------
 *          (a) `SENSITIVE_KEYS` EXPANDED from 7 to 27 entries.
 *
 *              The previous list covered the top-level credential-shaped
 *              keys most commonly used by Laravel and MASES validators,
 *              but it missed entire families of authentication secrets:
 *
 *                · Token family — `access_token`, `bearer_token`,
 *                  `api_token`, `session_token`, `password_reset_token`,
 *                  `email_verification_token`.
 *
 *                · Secret family — `client_secret`, `api_secret`,
 *                  `mfa_secret`, `totp_secret`, `two_factor_secret`,
 *                  `two_factor_recovery_codes`.
 *
 *                · OTP / MFA code family — `otp`, `otp_code`,
 *                  `one_time_password`, `one_time_code`, `totp`,
 *                  `totp_code`, `mfa_code`, `verification_code`.
 *
 *              Any of the above, if ever passed into `log()` by a
 *              future caller emitting an MFA or account-lifecycle
 *              event, would have been persisted verbatim. The service
 *              already defines MFA event-type constants
 *              (`EVENT_MFA_*`) but had no filter coverage for the
 *              credential fields those events are most likely to
 *              carry — the exact gap that motivated H-19.
 *
 *          (b) MATCHING IS NOW CASE-INSENSITIVE.
 *
 *              The previous check was `in_array($key, self::SENSITIVE_KEYS, true)`.
 *              A caller using `Password`, `PASSWORD`, `Access_Token`,
 *              or `Token` as a key would bypass the filter. Laravel's
 *              own conventions are lowercase, so this was latent, but
 *              fragile. The comparison now lowercases the incoming key
 *              before matching. The constant itself remains lowercase
 *              and is not changed by this normalization.
 *
 *          (c) SANITIZATION IS NOW RECURSIVE.
 *
 *              The previous implementation inspected only the top level
 *              of `$data`. A payload such as
 *
 *                  ['context' => ['password' => 'x', 'token' => 'y']]
 *
 *              would have persisted both nested credentials verbatim,
 *              because array-valued entries were passed through
 *              unmodified. The filter now recurses into array values and
 *              applies the same rules at every level.
 *
 *          WHAT IS PRESERVED
 *          -----------------
 *          · The nested `authorization` payload from
 *            `LogAuthorizationFailureListener` is preserved intact.
 *            Recursion descends into it, finds no key on the extended
 *            `SENSITIVE_KEYS` list (`check_type`, `check_value`,
 *            `application`, `resource_type`, `resource_id`, `action`,
 *            `occurred_at`), and returns it unchanged. `authorization`
 *            remains deliberately absent from `SENSITIVE_KEYS`.
 *
 *          · `User` and `Tenant` instance filtering is unchanged.
 *
 *          · Objects without a `toArray()` method are still dropped.
 *
 *          · Legitimate audit fields — `email`, `reason`, `status`,
 *            `tenant_id`, `user_id`, `ip`, `user_agent`, `request_id`,
 *            `remember`, `resource_type`, `check_type`, `check_value`,
 *            `action`, `occurred_at` — all survive.
 *
 *          · The public contract "security events are always logged" is
 *            unchanged. No caller is modified. No signature is changed.
 *
 *          WHAT IS DELIBERATELY NOT ADDED
 *          ------------------------------
 *          · No value-pattern matching. Filtering on the *shape* of a
 *            value (e.g. `"Bearer "` prefix, JWT structure, hex
 *            strings) would risk dropping legitimate audit data whose
 *            values happen to resemble credentials. Out of scope for
 *            this CR.
 *
 *          · No substring or prefix matching. Matching substrings would
 *            drop unrelated keys (`tokenized_amount` for a `token`
 *            substring, `secretary_name` for a `secret` substring).
 *            The explicit-list approach is the conservative choice.
 *
 *          · No recursion depth cap. The service operates on caller-
 *            supplied arrays that are shallow in every known call path.
 *            If a future caller passes a pathological depth, that is a
 *            separate concern with its own CR.
 *
 *          · No `authorization` re-add. v1.0.2's removal stands.
 *
 *          DOCUMENTATION FIX
 *          -----------------
 *          The v1.0.2 docblock for `SENSITIVE_KEYS` stated "eight
 *          credential-shaped keys" and then listed seven. The count is
 *          corrected in this revision and the docblock rewritten to
 *          describe the three credential families.
 *
 *          No API change. No signature change. No caller modification.
 *          No existing test assertion is invalidated.
 *
 * @package MaaleArna\MASES\Core\Security\Services
 * @version 1.0.3
 */
final class SecurityEventService
{
    // =========================================================================
    // Event type constants
    // =========================================================================
    //
    // These string constants are the canonical `security_events.event_type`
    // values emitted by this service. Callers should reference the constants
    // rather than the literal strings so that a rename is a single-site
    // change. The listener layer (`LogAuthorizationFailureListener`) also
    // declares its own constant for `authorization.denied` — that value is
    // deliberately not enumerated here because it is owned by the
    // authorization subsystem, not by the security service.
    // =========================================================================

    public const EVENT_LOGIN_SUCCESS = 'login.successful';
    public const EVENT_LOGIN_FAILED = 'login.failed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_REGISTRATION = 'registration';
    public const EVENT_PASSWORD_RESET_REQUESTED = 'password.reset.requested';
    public const EVENT_PASSWORD_RESET_COMPLETED = 'password.reset.completed';
    public const EVENT_PASSWORD_CHANGED = 'password.changed';
    public const EVENT_EMAIL_VERIFICATION_REQUESTED = 'email.verification.requested';
    public const EVENT_EMAIL_VERIFIED = 'email.verified';
    public const EVENT_MFA_ENABLED = 'mfa.enabled';
    public const EVENT_MFA_DISABLED = 'mfa.disabled';
    public const EVENT_MFA_VERIFIED = 'mfa.verified';
    public const EVENT_MFA_FAILED = 'mfa.failed';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious.activity';
    public const EVENT_ACCOUNT_LOCKED = 'account.locked';
    public const EVENT_ACCOUNT_UNLOCKED = 'account.unlocked';
    public const EVENT_ACCOUNT_DEACTIVATED = 'account.deactivated';
    public const EVENT_ACCOUNT_REACTIVATED = 'account.reactivated';

    /**
     * Default severity mapping for event types that do not explicitly provide one.
     *
     * Only event types that warrant a severity other than `'info'` need an
     * entry here. Everything else falls through to `'info'` in `log()`.
     *
     * The mapping is intentionally narrow:
     *   - `login.failed` and `mfa.failed` are warnings because they are the
     *     leading indicator of credential-stuffing / brute-force activity.
     *   - `password.reset.requested` is a warning because it is the first
     *     step of the account-takeover flow and its rate is worth monitoring.
     *   - `suspicious.activity` and `account.locked` are critical because
     *     they are terminal conditions raised by detection logic elsewhere
     *     in the platform, not by routine user actions.
     *
     * Callers that want a different severity for a specific event pass it
     * explicitly to `log($eventType, $data, $severity, $description)`.
     *
     * @var array<string, string>
     */
    private const DEFAULT_SEVERITY_MAP = [
        self::EVENT_LOGIN_FAILED          => 'warning',
        self::EVENT_MFA_FAILED            => 'warning',
        self::EVENT_PASSWORD_RESET_REQUESTED => 'warning',
        self::EVENT_SUSPICIOUS_ACTIVITY   => 'critical',
        self::EVENT_ACCOUNT_LOCKED        => 'critical',
        // All others default to 'info'.
    ];

    /**
     * Keys whose presence in a `$data` payload causes the associated value
     * to be dropped before persistence and logging.
     *
     * The filter is applied RECURSIVELY: the same rules are applied to the
     * top-level keys of `$data` and to the keys of every nested array
     * value. See `buildEventData()` for the algorithm.
     *
     * Matching is CASE-INSENSITIVE: the incoming key is lowercased before
     * comparison against this list. The list itself is stored lowercase.
     *
     * WHAT BELONGS ON THIS LIST
     * -------------------------
     * Credential-shaped values that, if ever mistakenly passed into a
     * security event payload, would constitute a leak of a secret. The
     * names are grouped by the credential family they represent.
     *
     *   Direct credential fields
     *     password                — the raw credential in a login or reset payload
     *     password_confirmation   — the mirror field in a reset payload
     *     current_password        — the "old password" field on a change form
     *
     *   Generic token field
     *     token                   — an unspecified bearer, CSRF, or one-time token
     *
     *   Token family
     *     access_token            — the short-lived half of an OAuth / API token pair
     *     bearer_token            — an explicitly-named bearer credential
     *     api_token               — a long-lived API credential
     *     session_token           — a session-persistence credential
     *     password_reset_token    — the token embedded in a password-reset link
     *     email_verification_token — the token embedded in a verification link
     *     refresh_token           — the long-lived half of a token pair
     *
     *   Secret family
     *     secret                  — an unspecified shared secret
     *     api_key                 — a long-lived API credential, key form
     *     api_secret              — a long-lived API credential, secret form
     *     client_secret           — an OAuth client credential
     *     mfa_secret              — the seed of a TOTP authenticator
     *     totp_secret             — an explicit TOTP seed
     *     two_factor_secret       — a two-factor seed
     *     two_factor_recovery_codes — the recovery-code set for two-factor auth
     *
     *   OTP / MFA code family
     *     otp                     — a generic one-time password
     *     otp_code                — a one-time password in code form
     *     one_time_password       — a spelled-out one-time password
     *     one_time_code           — a spelled-out one-time code
     *     totp                    — a generic TOTP code
     *     totp_code               — a TOTP code in code form
     *     mfa_code                — a code presented during an MFA challenge
     *     verification_code       — a code presented during any verification flow
     *
     * WHAT DOES NOT BELONG ON THIS LIST
     * ---------------------------------
     * The name `'authorization'` was historically included, on the theory
     * that an incoming `Authorization:` request header might leak into the
     * payload. In practice this service never sees request headers — the
     * caller supplies `$data` explicitly, and no caller passes raw headers.
     * What does arrive under the key `'authorization'` is the structured
     * denial payload produced by `LogAuthorizationFailureListener`, which is
     * built exclusively from `AuthorizationFailureEvent::toSecurityEventData()`
     * and contains only check_type, check_value, application, resource_type,
     * resource_id, action, and occurred_at. None of those is a credential.
     *
     * The key was removed from this list in v1.0.2 and is deliberately NOT
     * reinstated by v1.0.3. The recursive filter descends into the
     * `authorization` payload, finds no sensitive keys at any level, and
     * returns the payload unchanged.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        // Direct credential fields.
        'password',
        'password_confirmation',
        'current_password',

        // Generic token field.
        'token',

        // Token family.
        'access_token',
        'bearer_token',
        'api_token',
        'session_token',
        'password_reset_token',
        'email_verification_token',
        'refresh_token',

        // Secret family.
        'secret',
        'api_key',
        'api_secret',
        'client_secret',
        'mfa_secret',
        'totp_secret',
        'two_factor_secret',
        'two_factor_recovery_codes',

        // OTP / MFA code family.
        'otp',
        'otp_code',
        'one_time_password',
        'one_time_code',
        'totp',
        'totp_code',
        'mfa_code',
        'verification_code',
    ];

    /**
     * Log a security event.
     *
     * Security events are **always** persisted, regardless of the
     * `mases.features.audit_enabled` flag. That flag governs non-security
     * audit logs (file uploads, business-record changes). Gating security
     * events on that flag would mean an attacker who can flip a runtime
     * config value can also silence the security audit trail. The two
     * concerns are deliberately decoupled.
     *
     * Resolution order for each column:
     *
     *   user_id     — explicit `$data['user_id']`, else `$data['user']->id`
     *                 (when a User instance is passed), else null.
     *   tenant_id   — explicit `$data['tenant_id']` (authoritative when the
     *                 caller has already determined the tenant), else the
     *                 result of `mases_tenant_id()`. Under C-09 the helper
     *                 reads from `TenantContextService` in HTTP context.
     *   ip_address  — `$data['ip']` or `$data['ip_address']`, else the
     *                 current request's `ip()`.
     *   user_agent  — `$data['user_agent']`, else the current request's
     *                 `userAgent()`.
     *   request_id  — `$data['request_id']`, else `request()->id()` if the
     *                 macro is defined, else the `X-Request-ID` header.
     *   severity    — explicit `$severity`, else the mapping in
     *                 `DEFAULT_SEVERITY_MAP`, else `'info'`.
     *   description — explicit `$description`, else the lookup in
     *                 `getDefaultDescription()`.
     *   event_data  — `$data` after `buildEventData()` filtering.
     *
     * @param string $eventType     The type of event (e.g., 'login.successful').
     * @param array<string, mixed> $data        Additional context (user_id, tenant_id, email, ip, etc.).
     * @param string|null $severity   Override severity; if null, auto‑determined.
     * @param string|null $description Human‑readable description; if null, auto‑generated.
     * @return SecurityEvent|null The created event, or null if creation fails (fallback to log).
     */
    public function log(
        string $eventType,
        array $data = [],
        ?string $severity = null,
        ?string $description = null
    ): ?SecurityEvent {
        // Security events are ALWAYS logged – no feature flag check.
        // (The audit_enabled flag only affects non‑security audit logs.)

        // ---------------------------------------------------------------------
        // Determine severity.
        // Priority: explicit > auto-mapped > 'info'.
        // ---------------------------------------------------------------------
        if ($severity === null) {
            $severity = self::DEFAULT_SEVERITY_MAP[$eventType] ?? 'info';
        }

        // ---------------------------------------------------------------------
        // Resolve the user ID.
        // Callers may pass either a scalar user_id or a User instance under
        // the `user` key. Both shapes are accepted because different parts
        // of the platform use different conventions and forcing a single
        // shape at every call site would be a large mechanical change with
        // no behavioural benefit.
        // ---------------------------------------------------------------------
        $userId = $data['user_id'] ?? null;
        if ($userId === null && isset($data['user'])) {
            $user = $data['user'];
            if ($user instanceof User) {
                $userId = $user->id;
            } elseif (is_array($user) && isset($user['id'])) {
                $userId = $user['id'];
            }
        }

        // ---------------------------------------------------------------------
        // Resolve the tenant ID.
        //
        // Priority:
        //   1. The caller's explicit `$data['tenant_id']` — authoritative
        //      when present. A caller that has already determined the tenant
        //      (an audit listener processing an event whose payload carries
        //      the tenant, a console command operating within a known tenant)
        //      passes it here.
        //   2. `mases_tenant_id()` — the platform's tenant-context accessor.
        //      In HTTP context this reads from `TenantContextService` (v1.1.0),
        //      i.e. the tenant the middleware pipeline resolved and verified.
        //      In non-HTTP context it uses the user-default → config-default
        //      fallback chain.
        //
        // The pre-v1.0.1 fallback that read `Request::has('tenant_id')`
        // directly has been removed. See the class docblock revision history.
        // ---------------------------------------------------------------------
        $tenantId = $data['tenant_id'] ?? null;
        if ($tenantId === null && function_exists('mases_tenant_id')) {
            $tenantId = mases_tenant_id();
        }

        // ---------------------------------------------------------------------
        // Resolve the client IP address.
        // Priority: explicit `ip` > explicit `ip_address` > current request.
        // The two explicit key names are both supported because different
        // call sites adopted different conventions before this service was
        // consolidated; both are honoured to avoid a call-site migration.
        // ---------------------------------------------------------------------
        $ipAddress = $data['ip'] ?? $data['ip_address'] ?? null;
        if ($ipAddress === null) {
            $ipAddress = request()->ip();
        }

        // ---------------------------------------------------------------------
        // Resolve the user agent.
        // ---------------------------------------------------------------------
        $userAgent = $data['user_agent'] ?? request()->userAgent();

        // ---------------------------------------------------------------------
        // Resolve the request ID.
        // Priority: explicit `request_id` > `request()->id()` macro >
        // `X-Request-ID` header. The `id()` macro is provided by request
        // middleware in some deployments; when absent, the header is the
        // fallback. Either path may return null, which is a valid stored
        // value.
        // ---------------------------------------------------------------------
        $requestId = $data['request_id'] ?? null;
        if ($requestId === null && method_exists(request(), 'id')) {
            $requestId = request()->id();
        }
        if ($requestId === null) {
            $requestId = request()->header('X-Request-ID');
        }

        // ---------------------------------------------------------------------
        // Build the persisted event_data payload via the sensitive-key
        // filter. See `buildEventData()` and the class docblock for the
        // rationale behind the filter's contents.
        // ---------------------------------------------------------------------
        $eventData = $this->buildEventData($data);

        // ---------------------------------------------------------------------
        // Generate a default description if none was provided.
        // ---------------------------------------------------------------------
        if ($description === null) {
            $description = $this->getDefaultDescription($eventType);
        }

        // ---------------------------------------------------------------------
        // Persist the event and mirror it to the application log.
        // ---------------------------------------------------------------------
        try {
            $event = new SecurityEvent();
            $event->user_id = $userId;
            $event->tenant_id = $tenantId;
            $event->event_type = $eventType;
            $event->severity = $severity;
            $event->description = $description;
            $event->event_data = $eventData;
            $event->ip_address = $ipAddress;
            $event->user_agent = $userAgent;
            $event->request_id = $requestId;
            // Manually set created_at because the model has $timestamps = false.
            $event->created_at = Carbon::now();
            $event->save();

            // Also write to the application log for real‑time monitoring.
            // The log line carries the same identifiers as the DB row so
            // that a log aggregator and a DB query can be correlated by
            // event_type + user_id + tenant_id + request_id.
            Log::channel('stack')->log(
                $this->mapSeverityToLogLevel($severity),
                sprintf(
                    'Security event: %s (user: %s, tenant: %s, ip: %s)',
                    $eventType,
                    $userId ?? 'system',
                    $tenantId ?? 'global',
                    $ipAddress ?? 'unknown'
                ),
                ['event_type' => $eventType, 'user_id' => $userId, 'tenant_id' => $tenantId, 'data' => $eventData]
            );

            return $event;
        } catch (\Throwable $e) {
            // If the database is unavailable, fall back to logging only.
            // This preserves the fact that the event occurred even when the
            // persistent trail cannot be written. The log call uses the
            // default channel rather than the `stack` channel used above,
            // because a failure here may indicate the stack channel's
            // underlying handler is also compromised.
            Log::error('Failed to log security event to database', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
                'data'       => $eventData,
            ]);
            return null;
        }
    }

    /**
     * Convenience: log a successful login.
     *
     * Accepts either a User instance or a scalar user ID so callers do not
     * have to dereference the model when they already hold its identifier.
     */
    public function logLoginSuccess(User|int $user, array $data = []): ?SecurityEvent
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $this->log(
            self::EVENT_LOGIN_SUCCESS,
            array_merge($data, ['user_id' => $userId]),
            'info',
            'User logged in successfully.'
        );
    }

    /**
     * Convenience: log a failed login.
     *
     * The email is recorded under `email` and the failure reason under
     * `reason` so that both are available to downstream anomaly detection
     * without requiring access to the request body.
     */
    public function logLoginFailed(string $email, ?string $reason = null, array $data = []): ?SecurityEvent
    {
        return $this->log(
            self::EVENT_LOGIN_FAILED,
            array_merge($data, ['email' => $email, 'reason' => $reason]),
            'warning',
            sprintf('Login failed for email: %s', $email)
        );
    }

    /**
     * Convenience: log a logout.
     *
     * Accepts either a User instance or a scalar user ID for the same
     * reason as `logLoginSuccess()`.
     */
    public function logLogout(User|int $user, array $data = []): ?SecurityEvent
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $this->log(
            self::EVENT_LOGOUT,
            array_merge($data, ['user_id' => $userId]),
            'info',
            'User logged out.'
        );
    }

    /**
     * Convenience: log suspicious activity.
     *
     * Always critical. The description is caller-supplied because
     * suspicious activity is domain-specific and cannot be described
     * from the event type alone.
     */
    public function logSuspiciousActivity(string $description, array $data = []): ?SecurityEvent
    {
        return $this->log(
            self::EVENT_SUSPICIOUS_ACTIVITY,
            $data,
            'critical',
            $description
        );
    }

    /**
     * Build the persisted `event_data` payload.
     *
     * The filter is applied recursively: the same rules run at the top
     * level of `$data` and inside every nested array value. The function
     * is its own recursion point — nested arrays are passed back through
     * `buildEventData()`.
     *
     * Four rules, applied per key:
     *
     *   1. DROP any key whose lowercased form is in `SENSITIVE_KEYS`.
     *      Matching is case-insensitive: `Password`, `PASSWORD`, and
     *      `password` all match. The associated value is a credential,
     *      token, or secret and must never be persisted or logged. See
     *      the constant's docblock for the list and the rationale for
     *      each entry.
     *
     *   2. DROP any value that is a `User` or `Tenant` instance. Those
     *      objects have already been reduced to their scalar identifiers
     *      (`user_id`, `tenant_id`) by the caller and re-serialising the
     *      whole model would duplicate the identifier, inflate the JSON
     *      column, and risk capturing relation data that should not be
     *      persisted. Callers that want extra user fields must extract
     *      them explicitly.
     *
     *   3. RECURSE into any array value. The nested array is sanitised by
     *      a recursive call to this method, and the sanitised result is
     *      stored under the original key. If every entry of the nested
     *      array is dropped, the key remains with an empty array value —
     *      the key's presence is preserved because the presence itself
     *      may be audit-meaningful (e.g. an empty `authorization` payload
     *      is still evidence that an authorization event was recorded,
     *      though in practice the payload is never empty).
     *
     *   4. DROP any object value that does not implement `toArray()`.
     *      Arbitrary objects cannot be reliably serialised to JSON without
     *      risking data leakage through magic `__toString` or through
     *      property traversal; excluding them forces the caller to be
     *      explicit about what should be stored.
     *
     * Values that are null, empty strings, scalars, and `toArray`-capable
     * objects pass through unchanged.
     *
     * THE `authorization` PAYLOAD
     * ---------------------------
     * The nested `authorization` array produced by
     * `LogAuthorizationFailureListener` is preserved. Under the recursive
     * filter, the array is descended into, each of its keys
     * (`check_type`, `check_value`, `application`, `resource_type`,
     * `resource_id`, `action`, `occurred_at`) is checked against
     * `SENSITIVE_KEYS`, and none match — so all of them survive intact.
     * The name `authorization` itself remains absent from
     * `SENSITIVE_KEYS`, as it has been since v1.0.2.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function buildEventData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // -----------------------------------------------------------------
            // Rule 1 — drop credential-shaped keys (case-insensitive).
            // Non-string keys (integer array indices) never match and are
            // passed through to the remaining rules.
            // -----------------------------------------------------------------
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            // -----------------------------------------------------------------
            // Rule 2 — drop User and Tenant model instances.
            // -----------------------------------------------------------------
            if ($value instanceof User || $value instanceof Tenant) {
                continue;
            }

            // -----------------------------------------------------------------
            // Rule 3 — recurse into array values. This is what closes the
            // "nested credentials" bypass. The recursion terminates when
            // the nested structure bottoms out at scalars or nulls.
            // -----------------------------------------------------------------
            if (is_array($value)) {
                $sanitized[$key] = $this->buildEventData($value);
                continue;
            }

            // -----------------------------------------------------------------
            // Rule 4 — drop objects without a toArray() method.
            // -----------------------------------------------------------------
            if (is_object($value) && !method_exists($value, 'toArray')) {
                continue;
            }

            // Pass through: null, scalar, string, bool, and objects that
            // implement toArray().
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Get a default description for a known event type.
     *
     * Descriptions are intentionally in English at persistence time.
     * Localization for display is applied by the consumer of the
     * `security_events` table, not by this service, so that stored rows
     * remain stable even if the platform's default locale changes.
     *
     * Unknown event types fall through to a generic description. This is
     * a deliberate tolerance: domain subsystems are permitted to introduce
     * new event types without registering them here, at the cost of a
     * less specific human-readable summary. The event_type field itself
     * remains the authoritative discriminator.
     */
    private function getDefaultDescription(string $eventType): string
    {
        $descriptions = [
            self::EVENT_LOGIN_SUCCESS           => 'User logged in successfully.',
            self::EVENT_LOGIN_FAILED            => 'Login attempt failed.',
            self::EVENT_LOGOUT                  => 'User logged out.',
            self::EVENT_REGISTRATION            => 'New user registered.',
            self::EVENT_PASSWORD_RESET_REQUESTED => 'Password reset requested.',
            self::EVENT_PASSWORD_RESET_COMPLETED => 'Password reset completed.',
            self::EVENT_PASSWORD_CHANGED        => 'User password changed.',
            self::EVENT_EMAIL_VERIFICATION_REQUESTED => 'Email verification requested.',
            self::EVENT_EMAIL_VERIFIED          => 'Email verified successfully.',
            self::EVENT_MFA_ENABLED             => 'MFA enabled for user.',
            self::EVENT_MFA_DISABLED            => 'MFA disabled for user.',
            self::EVENT_MFA_VERIFIED            => 'MFA verified successfully.',
            self::EVENT_MFA_FAILED              => 'MFA verification failed.',
            self::EVENT_SUSPICIOUS_ACTIVITY     => 'Suspicious activity detected.',
            self::EVENT_ACCOUNT_LOCKED          => 'Account locked.',
            self::EVENT_ACCOUNT_UNLOCKED        => 'Account unlocked.',
            self::EVENT_ACCOUNT_DEACTIVATED     => 'Account deactivated.',
            self::EVENT_ACCOUNT_REACTIVATED     => 'Account reactivated.',
        ];

        return $descriptions[$eventType] ?? 'Security event occurred.';
    }

    /**
     * Map the internal severity vocabulary to Monolog log levels.
     *
     * The internal vocabulary has three values (`info`, `warning`,
     * `critical`) and maps one-to-one onto Monolog's own. The mapping
     * exists so the internal vocabulary can evolve (e.g. add a `notice`
     * tier) without requiring every call site to know how to translate
     * it for the log channel.
     */
    private function mapSeverityToLogLevel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'warning'  => 'warning',
            default    => 'info',
        };
    }
}