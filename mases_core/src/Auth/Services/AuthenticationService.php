<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\MASES\Core\Auth\Events\FailedLogin;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;

/**
 * AuthenticationService
 *
 * Central service for handling all authentication operations in the MASES
 * platform, with full tenant awareness and security event logging.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.8.0  — prior version. Login and logout security events were attributed
 *          to `mases_tenant_id()`.
 *
 * 1.9.0  — C-09 follow-up. Login and logout security events were no longer
 *          attributed via `mases_tenant_id()`; they used the explicit
 *          `$tenantId` parameter (login) or `$user->default_tenant_id`
 *          (logout). See the note below for the full rationale.
 *
 * 1.9.1  — Correction to v1.9.0. The login security event's `tenant_id`
 *          now resolves as:
 *
 *              mases_tenant_id() ?? $tenantId ?? $user->default_tenant_id
 *
 *          instead of v1.9.0's:
 *
 *              $tenantId ?? $user->default_tenant_id
 *
 *          WHY v1.9.0 WAS INSUFFICIENT
 *          ---------------------------
 *          v1.9.0 correctly fixed the case where `mases_tenant_id()`
 *          returned null on `/api/login` (which carries no
 *          `tenant.membership` middleware), causing the security event to
 *          record `tenant_id = null` even when the user had authenticated
 *          against a specific tenant supplied via the body.
 *
 *          But it over-corrected: it made the BODY tenant the sole source,
 *          so a request that carried BOTH an `X-Tenant-ID` header (the
 *          middleware-verified tenant, call it A) AND a body `tenant_id`
 *          (call it B) recorded B — even though the header value is the
 *          more authoritative of the two under the C-09 model.
 *
 *          The T4 regression test
 *          (`C09/TenantResolutionTest::security_event_carries_verified_tenant`)
 *          exercises exactly this case and failed under v1.9.0.
 *
 *          WHY v1.9.1 IS CORRECT
 *          ---------------------
 *          The new resolution order matches the C-09 rule that the
 *          header-derived tenant is authoritative when present, while
 *          still allowing the body parameter to serve as the tenant
 *          attribution when no header context exists:
 *
 *            1. `mases_tenant_id()` — post-C-09, returns the
 *               middleware-verified tenant (header or subdomain) in HTTP
 *               context, or null if none was resolved. This is the correct
 *               first choice.
 *
 *            2. `$tenantId` — the explicit login parameter. Used when no
 *               header-context tenant exists. `/api/login` deliberately
 *               has no `tenant.membership` middleware, so this fallback is
 *               the common case.
 *
 *            3. `$user->default_tenant_id` — last resort.
 *
 *          `logout()` is unchanged from v1.9.0. Logout sends neither a
 *          header nor a body tenant in normal use; the user's default
 *          tenant remains the correct attribution.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * Every authentication concern on the platform passes through this service:
 *
 *   - Credential verification and bearer-token issuance (login).
 *   - Token revocation, blacklisting, and refresh (logout / refreshToken).
 *   - User creation with bilingual Ethiopian name handling (register).
 *   - Password reset request and completion.
 *   - Email verification.
 *   - Current-user resolution.
 *
 * Controllers must not perform these operations directly. They call this
 * service; this service is the single point where authentication events,
 * security logging, and tenant-membership checks are consistently applied.
 *
 * -----------------------------------------------------------------------------
 * Token revocation — dual mechanism
 * -----------------------------------------------------------------------------
 * Revoking a Sanctum token requires two complementary mechanisms, because
 * the framework can resolve a token from two different caches:
 *
 *   1. DATABASE DELETION — removes the `personal_access_tokens` row so
 *      `PersonalAccessToken::findToken()` returns null on a fresh lookup.
 *
 *   2. CACHE BLACKLIST — adds `revoked_token:{sha256(full_bearer)}` to the
 *      cache. Any code path that caches the token model (or a guard that
 *      caches the resolved user) will consult this blacklist on the next
 *      request and reject the token outright.
 *
 * Both mechanisms run on every logout and refresh. They are redundant by
 * design: either one alone is sufficient to reject a token, and together
 * they cover every code path (PHP-FPM, Octane, and the test harness).
 *
 * -----------------------------------------------------------------------------
 * Stateless API login
 * -----------------------------------------------------------------------------
 * `login()` uses a manual credential check (`Hash::check`) rather than
 * `Auth::attempt()`. Sanctum's guard falls back to the session-based `web`
 * guard before trying bearer tokens, so a session created by `Auth::attempt`
 * would keep the user authenticated after their token was revoked. Avoiding
 * `Auth::attempt` keeps the API purely token-based.
 *
 * -----------------------------------------------------------------------------
 * Name handling — Amharic first, English second (Phase 1 Amendment A13)
 * -----------------------------------------------------------------------------
 * The MASES `users` table stores Ethiopian names in six separate bilingual
 * columns:
 *
 *     first_name_am        first_name_en
 *     father_name_am       father_name_en
 *     grandfather_name_am  grandfather_name_en
 *
 * There is no `name` column. Any code path that attempts to write a single
 * `name` string into the `User` model will be silently ignored by Laravel's
 * mass-assignment protection (because `name` is not in `$fillable`), and all
 * six columns will remain NULL. That defect was the reason A13 exists.
 *
 * The register() method now handles names in two ways:
 *
 *   1. STRUCTURED (preferred) — the caller supplies any subset of the six
 *      bilingual fields inside `$additionalData`. These are used as given.
 *
 *   2. LEGACY (compatibility) — the caller supplies only the single `$name`
 *      string. The string is split on the FIRST whitespace boundary.
 *
 * The model accessor `User::getNameAttribute()` (Phase 1 Amendment A4)
 * composes a read-only `name` attribute from the six columns using the same
 * Amharic-first policy, so existing code that reads `$user->name` continues
 * to work without change.
 *
 * -----------------------------------------------------------------------------
 * Failed-login events (Phase 1 Change Request CR-006)
 * -----------------------------------------------------------------------------
 * `login()` dispatches a `FailedLogin` domain event immediately before it
 * raises its `AuthenticationException`. This gives registered listeners
 * (e.g. `LogFailedLogin`, A7b) a hook to write to the audit trail without
 * inspecting the exception or reconstructing the request context.
 *
 * Laravel's own `Illuminate\Auth\Events\Failed` is NOT suitable here,
 * because it only fires from `Auth::attempt()`, which this service
 * deliberately does not use.
 *
 * The event carries the attempted email, a machine-readable failure
 * reason, the guard name, and (when the email resolves to a real account)
 * the user ID. It does not carry the password or the request object.
 *
 * -----------------------------------------------------------------------------
 * Phase 2 compatibility
 * -----------------------------------------------------------------------------
 * Phase 2 will attach Shareholder, Employee, and other domain records to
 * users. This service does not create those records — that responsibility
 * belongs to the respective domain services (e.g. ShareholderService). The
 * register() method remains focused on user identity, tenant membership,
 * and email-verification state.
 *
 * @package MaaleArna\MASES\Core\Auth\Services
 * @version 1.9.1
 */
final class AuthenticationService
{
    /**
     * Cache key prefix for revoked-token blacklist entries.
     */
    private const REVOKED_TOKEN_CACHE_PREFIX = 'revoked_token:';

    /**
     * Default blacklist TTL in seconds when Sanctum's expiration is null
     * (i.e., tokens never expire) or unset.
     */
    private const DEFAULT_BLACKLIST_TTL = 60 * 60 * 24 * 30; // 30 days

    /**
     * The security event service for logging authentication-related events.
     *
     * @var SecurityEventService|null
     */
    private ?SecurityEventService $securityEventService = null;

    /**
     * Create a new AuthenticationService instance.
     *
     * @param SecurityEventService|null $securityEventService
     */
    public function __construct(?SecurityEventService $securityEventService = null)
    {
        $this->securityEventService = $securityEventService;
    }

    // =========================================================================
    // Authentication (Login, Logout, Token Refresh)
    // =========================================================================

    /**
     * Attempt to log a user in with email and password.
     *
     * Performs a stateless credential check and issues a Sanctum bearer token.
     *
     * Sequence:
     *   1. Look up an active user by email. If not found, or if the password
     *      does not match, dispatch `FailedLogin` with reason
     *      'invalid_credentials', log a `login.failed` security event, and
     *      raise `AUTH_INVALID_CREDENTIALS` (HTTP 401).
     *   2. If a tenant context is supplied, verify that the user belongs to
     *      that tenant. If not, dispatch `FailedLogin` with reason
     *      'tenant_mismatch', log `login.failed` with the appropriate
     *      reason, and raise `AUTH_TENANT_ACCESS_DENIED` (HTTP 403).
     *   3. Issue a Sanctum personal access token with the `*` ability.
     *   4. Dispatch the framework `Login` event so any registered listener
     *      (e.g. `LogSuccessfulLogin`, A6) runs.
     *   5. Log a `login.successful` security event.
     *
     * A stateless check is used deliberately instead of `Auth::attempt()`;
     * see the class docblock for the reasoning.
     *
     * -----------------------------------------------------------------------------
     * Failure-reason vocabulary (CR-006, Option 1)
     * -----------------------------------------------------------------------------
     * The current implementation queries `is_active = true` together with the
     * email lookup, so an inactive account and a wrong password produce the
     * same `AUTH_INVALID_CREDENTIALS` response and the same failure reason
     * ('invalid_credentials'). This is deliberate: it prevents account
     * enumeration — an attacker cannot distinguish "email does not exist"
     * from "email exists but is disabled" from "wrong password".
     *
     * A future CR may split the query to distinguish these cases, in which
     * case the inactive-account path would dispatch with
     * reason 'inactive_account'. The `FailedLogin` event contract already
     * supports that value.
     *
     * @param string          $email
     * @param string          $password
     * @param bool            $remember
     * @param int|string|null $tenantId
     * @param string|null $mfaChallengeToken
     * @param string|null $mfaCode TOTP code or one-time recovery code
     *
     * @return array{user: User, token?: string, mfa_required?: bool, challenge_token?: string}
     *
     * @throws AuthenticationException
     */
    public function login(
        string $email,
        string $password,
        bool $remember = false,
        int|string|null $tenantId = null,
        ?string $mfaChallengeToken = null,
        ?string $mfaCode = null,
        bool $issueToken = true
    ): array {
        // ---------------------------------------------------------------------
        // 1. Stateless credential verification.
        // ---------------------------------------------------------------------
        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if (!$user instanceof User || !Hash::check($password, $user->password)) {
            // Dispatch the FailedLogin domain event first, so listeners run
            // before the exception reaches the canonical renderer. The event
            // is the audit-trail hook (A7b); the SecurityEventService call
            // below is the security-record hook. Both run for every failure.
            Event::dispatch(new FailedLogin(
                email:    $email,
                reason:   'invalid_credentials',
                guard:    'api',
                userId:   $user instanceof User ? $user->id : null,
                tenantId: is_int($tenantId) ? $tenantId : null,
            ));

            $this->logSecurityEvent('login.failed', [
                'email'      => $email,
                'reason'     => 'Invalid credentials or inactive account',
                'ip'         => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            throw new AuthenticationException(
                'Invalid email or password.',
                'AUTH_INVALID_CREDENTIALS',
                401
            );
        }

        // ---------------------------------------------------------------------
        // 2. Tenant membership validation.
        // ---------------------------------------------------------------------
        $effectiveTenantId = $tenantId ?? $user->default_tenant_id;

        if ($effectiveTenantId === null) {
            $eligibleTenantIds = $user->activeTenants()
                ->where('tenants.is_active', true)
                ->pluck('tenants.id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            // Never issue a token when the account has no single, safe tenant
            // context. A user with multiple memberships must select a tenant.
            if ($eligibleTenantIds->count() !== 1) {
                Event::dispatch(new FailedLogin(
                    email: $email,
                    reason: 'tenant_context_missing_or_ambiguous',
                    guard: 'api',
                    userId: $user->id,
                    tenantId: null,
                ));

                $this->logSecurityEvent('login.failed', [
                    'email' => $email,
                    'reason' => 'Tenant context missing or ambiguous',
                    'ip' => request()->ip(),
                ]);

                throw new AuthenticationException(
                    'Invalid email or password.',
                    'AUTH_INVALID_CREDENTIALS',
                    401
                );
            }

            $effectiveTenantId = $eligibleTenantIds->first();
        }

        $effectiveTenantId = (int) $effectiveTenantId;
        $tenantIsActive = Tenant::query()
            ->whereKey($effectiveTenantId)
            ->where('is_active', true)
            ->exists();
        $isMember = $tenantIsActive
            && $user->activeTenants()->where('tenants.id', $effectiveTenantId)->exists();

        if (!$isMember) {
            Event::dispatch(new FailedLogin(
                email: $email,
                reason: 'tenant_mismatch',
                guard: 'api',
                userId: $user->id,
                tenantId: $effectiveTenantId,
            ));

            $this->logSecurityEvent('login.failed', [
                'email' => $email,
                'tenant_id' => $effectiveTenantId,
                'reason' => 'User is not a member of an active specified tenant',
                'ip' => request()->ip(),
            ]);

            // Preserve the explicit tenant-selection denial contract. If the
            // tenant was implicit, use the neutral credential failure instead.
            if ($tenantId !== null) {
                throw new AuthenticationException(
                    'User does not have access to the specified tenant.',
                    'AUTH_TENANT_ACCESS_DENIED',
                    403
                );
            }

            throw new AuthenticationException(
                'Invalid email or password.',
                'AUTH_INVALID_CREDENTIALS',
                401
            );
        }

        $tenantId = $effectiveTenantId;

        // ---------------------------------------------------------------------
        // 3. Enforce TOTP MFA before issuing any bearer token.
        // ---------------------------------------------------------------------
        $mfa = app(TotpMfaService::class);
        if ($mfa->isEnabled($user)) {
            if ($mfaChallengeToken === null && $mfaCode === null) {
                $challengeToken = $mfa->createLoginChallenge($user, (int) $tenantId, $remember);
                $this->logSecurityEvent('login.mfa_challenge_issued', [
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                    'ip' => request()->ip(),
                ]);

                return [
                    'user' => $user,
                    'mfa_required' => true,
                    'challenge_token' => $challengeToken,
                ];
            }

            if (
                $mfaChallengeToken === null
                || $mfaCode === null
                || ! $mfa->completeLoginChallenge($user, (int) $tenantId, $mfaChallengeToken, $mfaCode)
            ) {
                $this->logSecurityEvent('login.mfa_failed', [
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                    'ip' => request()->ip(),
                ]);

                throw new AuthenticationException(
                    'Invalid authentication code or expired challenge.',
                    'AUTH_MFA_INVALID',
                    401
                );
            }

            $this->logSecurityEvent('login.mfa_succeeded', [
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'ip' => request()->ip(),
            ]);
        } elseif ($mfaChallengeToken !== null || $mfaCode !== null) {
            throw new AuthenticationException(
                'Invalid authentication challenge.',
                'AUTH_MFA_INVALID',
                401
            );
        }

        // ---------------------------------------------------------------------
        // 4. Issue a Sanctum bearer token only after required MFA succeeds.
        // ---------------------------------------------------------------------
        $token = $issueToken ? $user->createToken('auth_token', ['*'])->plainTextToken : null;

        if ($issueToken) {
            Event::dispatch(new Login('api', $user, $remember));
        }

        // ---------------------------------------------------------------------
        // 4. Security event — tenant attribution (v1.9.1).
        //
        // Resolution order:
        //
        //   1. `mases_tenant_id()` — post-C-09, the middleware-verified
        //      tenant (from the X-Tenant-ID header or subdomain) when one
        //      was resolved. This is the most authoritative value and is
        //      the correct first choice.
        //
        //   2. `$tenantId` — the explicit login parameter. Used when no
        //      header-context tenant exists. `/api/login` deliberately does
        //      not carry the `tenant.membership` middleware, so this
        //      fallback is the common case.
        //
        //   3. `$user->default_tenant_id` — last resort when neither of the
        //      above are available.
        //
        // See the class docblock "Revision history" for why v1.9.0 used only
        // values 2 and 3, and why v1.9.1 restores value 1 to the front of
        // the chain.
        // ---------------------------------------------------------------------
        $this->logSecurityEvent('login.successful', [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'tenant_id'  => mases_tenant_id() ?? $tenantId ?? $user->default_tenant_id,
            'remember'   => $remember,
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * Log the current or specified user out.
     *
     * Performs three steps in order:
     *   1. Add the incoming bearer token to the cache blacklist.
     *   2. Delete every token for the user from the database.
     *   3. Reset the auth guard cache so the next request re-resolves.
     *
     * @param User|null $user
     * @param bool      $revokeTokens
     *
     * @return void
     */
    public function logout(?User $user = null, bool $revokeTokens = true): void
    {
        $user = $user ?? Auth::user();

        if (!$user instanceof User) {
            return;
        }

        // ---------------------------------------------------------------------
        // Security event — tenant attribution (v1.9.0, unchanged in v1.9.1).
        //
        // Logout is user-scoped, not request-scoped: no tenant is specified
        // on the logout request. The correct attribution is the user's
        // default tenant.
        // ---------------------------------------------------------------------
        $this->logSecurityEvent('logout', [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'tenant_id'  => $user->default_tenant_id,
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Step 1: blacklist the incoming token in the cache.
        $this->blacklistCurrentToken();

        // Step 2: revoke tokens from the database.
        if ($revokeTokens) {
            $this->revokeAllTokens($user);
        } else {
            try {
                $current = $user->currentAccessToken();
                if ($current !== null && method_exists($current, 'delete')) {
                    $current->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke current access token', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        Event::dispatch(new Logout('api', $user));

        // Defensive: clear web session if present (no-op for stateless API).
        try {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }
        } catch (\Throwable $e) {
            Log::debug('Session guard logout skipped', ['error' => $e->getMessage()]);
        }

        // Step 3: reset auth guard cache for the next request.
        $this->resetAuthGuards();
    }

    /**
     * Refresh the user's authentication token.
     *
     * Blacklists the incoming token in the cache, revokes every token in the
     * database, and issues a fresh one.
     *
     * @param User $user
     *
     * @return string The new plain-text token.
     */
    public function refreshToken(User $user): string
    {
        // Step 1: blacklist the incoming token in the cache.
        $this->blacklistCurrentToken();

        // Step 2: revoke every token from the database.
        $this->revokeAllTokens($user);

        $this->logSecurityEvent('token.refresh', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'ip'      => request()->ip(),
        ]);

        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        // Step 3: reset the guard cache for the next request.
        $this->resetAuthGuards();

        return $token;
    }

    /**
     * Revoke every personal access token issued to the given user.
     *
     * @param User $user
     *
     * @return int Number of tokens removed by the bulk query.
     */
    public function revokeAllTokens(User $user): int
    {
        // Delete the transient current access token if present.
        try {
            $current = $user->currentAccessToken();
            if ($current !== null && method_exists($current, 'delete')) {
                $current->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to delete current access token', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Bulk delete every remaining token for this user.
        $deleted = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->delete();

        Log::debug('Revoked personal access tokens', [
            'user_id'       => $user->id,
            'deleted_count' => $deleted,
        ]);

        return $deleted;
    }

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Register a new user.
     *
     * Creates a `User` row with the six bilingual Ethiopian name columns
     * populated (see the class docblock section "Name handling"). Never
     * writes to a `name` column, because none exists. Never passes `name` to
     * `User::create()` — doing so would be silently discarded by Laravel's
     * mass-assignment protection.
     *
     * -----------------------------------------------------------------------------
     * Name resolution precedence
     * -----------------------------------------------------------------------------
     *   1. Any structured field present in `$additionalData` is used verbatim.
     *   2. Any structured field absent from `$additionalData` is left null.
     *   3. If `$additionalData` contains no structured name at all, the
     *      single-string `$name` is split on the first whitespace boundary:
     *        first token   → first_name_en
     *        remaining     → father_name_en
     *      This fallback is the approved temporary compatibility behaviour.
     *
     * -----------------------------------------------------------------------------
     * Duplicate email handling
     * -----------------------------------------------------------------------------
     * A `AUTH_EMAIL_TAKEN` exception (HTTP 409) is raised if the email already
     * exists. The controller deliberately does not include `unique:users,email`
     * in its validation rules so that this contract-specific error code reaches
     * the client instead of a generic 422 VALIDATION_ERROR.
     *
     * @param string               $name           Legacy single-string name; used only when no structured name is provided.
     * @param string               $email
     * @param string               $password
     * @param array<string, mixed> $additionalData Optional structured fields (see class docblock).
     * @param int|string|null      $tenantId
     *
     * @return User
     *
     * @throws AuthenticationException
     */
    public function register(
        string $name,
        string $email,
        string $password,
        array $additionalData = [],
        int|string|null $tenantId = null
    ): User {
        // ---------------------------------------------------------------------
        // 1. Duplicate email guard.
        // ---------------------------------------------------------------------
        if (User::where('email', $email)->exists()) {
            throw new AuthenticationException(
                'A user with this email already exists.',
                'AUTH_EMAIL_TAKEN',
                409
            );
        }

        // ---------------------------------------------------------------------
        // 2. Resolve the six bilingual name components.
        // ---------------------------------------------------------------------
        $names = $this->resolveNameComponents($name, $additionalData);

        // ---------------------------------------------------------------------
        // 3. Create the user row.
        //    IMPORTANT: do NOT pass 'name' here. There is no `name` column;
        //    including the key would be silently discarded by Laravel's
        //    mass-assignment protection and would mask the bug fixed by A13.
        // ---------------------------------------------------------------------
        $user = User::create([
            'first_name_en'       => $names['first_name_en'],
            'first_name_am'       => $names['first_name_am'],
            'father_name_en'      => $names['father_name_en'],
            'father_name_am'      => $names['father_name_am'],
            'grandfather_name_en' => $names['grandfather_name_en'],
            'grandfather_name_am' => $names['grandfather_name_am'],
            'email'               => $email,
            'password'            => Hash::make($password),
            'locale'              => $additionalData['locale']
                                     ?? config('mases.localization.default_locale', 'am'),
            'is_active'           => $additionalData['is_active'] ?? true,
            'default_tenant_id'   => $tenantId,
            'email_verified_at'   => $additionalData['email_verified_at'] ?? null,
        ]);

        // ---------------------------------------------------------------------
        // 4. Tenant membership.
        // ---------------------------------------------------------------------
        if ($tenantId !== null) {
            $tenant = Tenant::find($tenantId);
            if ($tenant && $tenant->is_active) {
                $user->tenants()->attach($tenantId, ['joined_at' => now()]);

                if ($user->default_tenant_id === null) {
                    $user->default_tenant_id = (int) $tenantId;
                    $user->save();
                }
            }
        }

        Event::dispatch(new Registered($user));

        $this->logSecurityEvent('registration', [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'tenant_id'  => $tenantId,
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $user;
    }

    // =========================================================================
    // Password Reset
    // =========================================================================

    /**
     * Send a password reset link to the given email address.
     *
     * @param string $email
     *
     * @return string
     */
    public function sendPasswordResetLink(string $email): string
    {
        $status = Password::sendResetLink(['email' => $email]);

        $this->logSecurityEvent('password.reset.requested', [
            'email'  => $email,
            'status' => $status,
            'ip'     => request()->ip(),
        ]);

        return $status;
    }

    /**
     * Reset the user's password using a valid token.
     *
     * @param array<string, mixed> $credentials
     *
     * @return string
     */
    public function resetPassword(array $credentials): string
    {
        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->password = Hash::make($password);
            $user->setRememberToken(Str::random(60));
            $user->save();

            Event::dispatch(new PasswordReset($user));

            $this->logSecurityEvent('password.reset.completed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'ip'      => request()->ip(),
            ]);
        });

        return $status;
    }

    // =========================================================================
    // Email Verification
    // =========================================================================

    /**
     * Verify a user's email address using the provided hash.
     *
     * @param User   $user
     * @param string $hash
     *
     * @return bool
     */
    public function verifyEmail(User $user, string $hash): bool
    {
        if (!hash_equals($hash, sha1($user->getEmailForVerification()))) {
            $this->logSecurityEvent('email.verification.failed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'reason'  => 'Invalid hash',
            ]);
            return false;
        }

        if ($user->hasVerifiedEmail()) {
            $this->logSecurityEvent('email.verification.already_verified', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);
            return true;
        }

        $user->markEmailAsVerified();

        $this->logSecurityEvent('email.verification.successful', [
            'user_id' => $user->id,
            'email'   => $user->email,
        ]);

        return true;
    }

    /**
     * Resend the email verification notification to the user.
     *
     * @param User $user
     *
     * @return bool
     */
    public function resendVerificationEmail(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            $this->logSecurityEvent('email.verification.resend.failed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'reason'  => 'Email already verified',
            ]);
            return false;
        }

        $user->sendEmailVerificationNotification();

        $this->logSecurityEvent('email.verification.resend.successful', [
            'user_id' => $user->id,
            'email'   => $user->email,
        ]);

        return true;
    }

    // =========================================================================
    // Current User & Authentication State
    // =========================================================================

    /**
     * Get the currently authenticated user.
     *
     * @return User|null
     */
    public function getCurrentUser(): ?User
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Check if the current request has an authenticated user.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return Auth::check();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the six bilingual name components from the supplied inputs.
     *
     * Precedence:
     *
     *   1. Structured fields in $additionalData — used verbatim when present.
     *      Missing structured fields remain null.
     *
     *   2. Legacy single-string $name — used only when $additionalData
     *      contains no structured name at all. The string is split on the
     *      FIRST whitespace boundary:
     *
     *          "Akefa Ayele Daka"  →  first_name_en = "Akefa"
     *                                 father_name_en = "Ayele Daka"
     *
     *      No further splitting is attempted. Guessing the mapping beyond
     *      the first space would require an approved rule; none exists. A
     *      single-token string (no whitespace) becomes first_name_en only.
     *
     *   3. Neither supplied — all six components are null. The `User` row is
     *      still valid because the schema allows null names.
     *
     * @param string               $name
     * @param array<string, mixed> $additionalData
     *
     * @return array{
     *     first_name_en:       string|null,
     *     first_name_am:       string|null,
     *     father_name_en:      string|null,
     *     father_name_am:      string|null,
     *     grandfather_name_en: string|null,
     *     grandfather_name_am: string|null,
     * }
     */
    private function resolveNameComponents(string $name, array $additionalData): array
    {
        // ---------------------------------------------------------------------
        // Priority 1 — structured fields from $additionalData.
        // ---------------------------------------------------------------------
        $structured = [
            'first_name_en'       => $additionalData['first_name_en']       ?? null,
            'first_name_am'       => $additionalData['first_name_am']       ?? null,
            'father_name_en'      => $additionalData['father_name_en']      ?? null,
            'father_name_am'      => $additionalData['father_name_am']      ?? null,
            'grandfather_name_en' => $additionalData['grandfather_name_en'] ?? null,
            'grandfather_name_am' => $additionalData['grandfather_name_am'] ?? null,
        ];

        // If any structured field was supplied, trust the caller's intent and
        // return the set as-is (including its nulls).
        if (array_filter($structured, static fn ($v) => $v !== null && $v !== '')) {
            return $structured;
        }

        // ---------------------------------------------------------------------
        // Priority 2 — legacy single-string name, split at the first space.
        // ---------------------------------------------------------------------
        $trimmed = trim($name);

        if ($trimmed === '') {
            return $structured; // all null
        }

        $spacePos = strpos($trimmed, ' ');

        if ($spacePos === false) {
            // No whitespace: the whole string is the first name.
            return [
                'first_name_en'       => $trimmed,
                'first_name_am'       => null,
                'father_name_en'      => null,
                'father_name_am'      => null,
                'grandfather_name_en' => null,
                'grandfather_name_am' => null,
            ];
        }

        $first = substr($trimmed, 0, $spacePos);
        $rest  = trim(substr($trimmed, $spacePos + 1));

        return [
            'first_name_en'       => $first !== '' ? $first : null,
            'first_name_am'       => null,
            'father_name_en'      => $rest !== '' ? $rest : null,
            'father_name_am'      => null,
            'grandfather_name_en' => null,
            'grandfather_name_am' => null,
        ];
    }

    /**
     * Add the incoming bearer token to the cache blacklist.
     *
     * The token is keyed by `revoked_token:{sha256(full_bearer)}`. The full
     * bearer value (i.e., `{id}|{plainText}`) is used because that is what
     * the client sends and what the test harness expects.
     *
     * @return void
     */
    private function blacklistCurrentToken(): void
    {
        $bearer = request()->bearerToken();

        if (!is_string($bearer) || $bearer === '') {
            return;
        }

        $hash = hash('sha256', $bearer);
        $cacheKey = self::REVOKED_TOKEN_CACHE_PREFIX . $hash;
        $ttl = $this->resolveBlacklistTtl();

        try {
            Cache::put($cacheKey, true, $ttl);

            Log::debug('Incoming bearer token blacklisted', [
                'hash_prefix' => substr($hash, 0, 8),
                'ttl_seconds' => $ttl,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to blacklist bearer token', [
                'hash_prefix' => substr($hash, 0, 8),
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the TTL for the blacklist entry.
     *
     * If Sanctum's `expiration` config is set (in minutes), we mirror that
     * so the blacklist entry lives exactly as long as the token would have.
     * If not set, we use a conservative 30-day default.
     *
     * @return int TTL in seconds.
     */
    private function resolveBlacklistTtl(): int
    {
        $expirationMinutes = config('sanctum.expiration');

        if (is_numeric($expirationMinutes) && (int) $expirationMinutes > 0) {
            return (int) $expirationMinutes * 60;
        }

        return self::DEFAULT_BLACKLIST_TTL;
    }

    /**
     * Clear the cached auth guards so the next request in the same process
     * re-resolves the user from scratch.
     *
     * @return void
     */
    private function resetAuthGuards(): void
    {
        try {
            app('auth')->forgetGuards();
        } catch (\Throwable $e) {
            Log::debug('Failed to reset auth guards', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log a security event using the injected security service.
     *
     * @param string               $eventType
     * @param array<string, mixed> $data
     *
     * @return void
     */
    private function logSecurityEvent(string $eventType, array $data): void
    {
        if ($this->securityEventService !== null) {
            $this->securityEventService->log($eventType, $data);
        } else {
            Log::debug('Security event not logged: SecurityEventService missing', [
                'event_type' => $eventType,
                'data'       => $data,
            ]);
        }
    }
}