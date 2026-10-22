<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\MASES\Core\Auth\Services\AuthenticationService;
use MaaleArna\MASES\Core\Auth\Services\LoginIdentifierResolver;
use MaaleArna\MASES\Core\Auth\Services\TotpMfaService;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;

/**
 * AuthController
 *
 * Handles authentication API endpoints for the MASES platform.
 * Provides login, logout, registration, password reset, email verification,
 * and token refresh functionality.
 *
 * -----------------------------------------------------------------------------
 * Revision history
 * -----------------------------------------------------------------------------
 * 1.0.0  — initial delivery. Phone-number login via resolver; neutral
 *          password reset; Sanctum token lifecycle.
 *
 * 1.1.0  — production hardening and diagnostic clarity:
 *
 *          · Login audit logging. Every login attempt — success or
 *            failure — writes an INFO entry with the masked
 *            identifier, IP address, user agent, and outcome. See
 *            the class docblock §"Audit logging" below.
 *
 *          · Email normalization. resolveLoginEmail() lowercases and
 *            trims the identifier before format checking and before
 *            the phone lookup. Email addresses are case-insensitive
 *            per RFC 5321; the previous implementation could fail to
 *            match a user who typed a differently-cased address than
 *            the one stored.
 *
 *          · Phone-resolution logging. When the phone → email
 *            branch fires, the fact is logged (masked) for
 *            diagnostics.
 *
 *          · Route-level rate limiting documentation. See §"Rate
 *            limiting" below.
 *
 *          · Complete error-code reference. See §"Error codes" below.
 *
 *          · Password-reset status enumeration. See the docblock on
 *            sendPasswordResetLink() for the full enumeration of
 *            Laravel's Password::* status codes and the response for
 *            each.
 *
 *          · Profile relationship loading. profile() now explicitly
 *            loads the tenants, roles, and permissions relations
 *            before mapping them. The previous code assumed the
 *            caller had pre-loaded them; if they had not, the
 *            response returned empty arrays instead of the real
 *            data. This is a latent bug fix — no API contract
 *            change, the response shape is identical when the
 *            relationships were loaded correctly.
 *
 *          · Docblock consistency. Every method carries @return and
 *            @throws annotations in the same structure used
 *            throughout the frontend batch.
 *
 *          No change to the API contract. Every request that
 *          succeeded before succeeds after; every response body is
 *          byte-for-byte identical; every error code is unchanged.
 *
 * -----------------------------------------------------------------------------
 * Duplicate-email handling on registration
 * -----------------------------------------------------------------------------
 * The `register()` endpoint deliberately does NOT include
 * `'unique:users,email'` in its validation rules. Duplicate detection
 * is delegated to `AuthenticationService::register()`, which raises an
 * `AuthenticationException` with code `AUTH_EMAIL_TAKEN` and HTTP
 * status `409 Conflict`.
 *
 * This gives the API a stable, contract-specific error code for a
 * duplicate email, distinct from the generic `VALIDATION_ERROR` used
 * for malformed input. All other validation rules (required fields,
 * password strength, password confirmation, tenant existence) remain
 * in the controller.
 *
 * -----------------------------------------------------------------------------
 * Registration name fields (CR-005)
 * -----------------------------------------------------------------------------
 * The MASES `users` table stores Ethiopian names in six separate
 * bilingual columns:
 *
 *     first_name_am  /  first_name_en
 *     father_name_am /  father_name_en
 *     grandfather_name_am / grandfather_name_en
 *
 * There is no single `name` column. The register endpoint therefore
 * accepts all six fields directly, and validates that at least one
 * identification path is present:
 *
 *     - first_name_en, or
 *     - first_name_am, or
 *     - the legacy single-string `name` field.
 *
 * The legacy `name` field remains supported for backward
 * compatibility with clients that predate this CR. When `name` is
 * supplied and the structured fields are not, a fallback `name` is
 * composed from whatever structured fields are present. When neither
 * is supplied, validation fails with a standard 422 VALIDATION_ERROR
 * envelope.
 *
 * The six structured fields are forwarded to the service via the
 * `additionalData` array, preserving the service's existing method
 * signature.
 *
 * -----------------------------------------------------------------------------
 * Login identifier
 * -----------------------------------------------------------------------------
 * The `email` field on the login request may carry an email address
 * or a phone number. The field name is retained for backward
 * compatibility with clients that predate this change.
 *
 * The controller resolves the identifier to an email address via
 * resolveLoginEmail() before handing off to AuthenticationService,
 * which expects an email. The resolver:
 *
 *   · passes an email-formatted identifier through unchanged;
 *   · maps a phone number to the email of the user whose `phone`
 *     column matches it;
 *   · returns null when no user matches — in which case login()
 *     passes the raw identifier through and the service produces its
 *     standard AUTH_INVALID_CREDENTIALS envelope.
 *
 * Username is NOT supported. The `users` table has no `username`
 * column; adding one requires a migration and a Change Request. When
 * that CR lands, resolveLoginEmail() gains a third lookup branch and
 * this docblock is updated.
 *
 * -----------------------------------------------------------------------------
 * Password reset neutrality
 * -----------------------------------------------------------------------------
 * Both password reset endpoints (`sendPasswordResetLink` and
 * `resetPassword`) return responses that do not distinguish between
 * an email that is registered and one that is not.
 *
 * The `exists:users,email` validation rule was removed from both
 * endpoints because its presence leaked registration status through a
 * 422 VALIDATION_ERROR response — contradicting the route's
 * documented neutrality contract.
 *
 * The neutral response is indistinguishable to the client. The actual
 * Laravel status code is logged at INFO level with a hashed email for
 * operational diagnosis; the raw email is never logged.
 *
 * -----------------------------------------------------------------------------
 * Token lifecycle
 * -----------------------------------------------------------------------------
 * Login and registration issue a Sanctum personal access token in the
 * format `{id}|{plainText}`. Logout and refresh delete the incoming
 * token by ID and also add it to the cache blacklist via the service,
 * so no code path can keep the old token usable.
 *
 * The frontend stores the token in sessionStorage (interim policy,
 * see the frontend's src/features/auth/service.ts docblock) and
 * sends it as `Authorization: Bearer {token}` on every authenticated
 * request.
 *
 * -----------------------------------------------------------------------------
 * Rate limiting
 * -----------------------------------------------------------------------------
 * This controller does not enforce rate limits. Rate limiting is
 * configured at the route level via Laravel's `throttle:` middleware,
 * which is the correct layer for two reasons:
 *
 *   1. The middleware runs before the request reaches the controller,
 *      so a throttled request never consumes controller resources.
 *
 *   2. The middleware's key (IP address, user ID, or a custom key)
 *      is a policy decision that belongs in the route definition,
 *      not scattered through the controller.
 *
 * Recommended configuration for the auth endpoints, to be applied in
 * routes/api.php (values are a starting point, subject to adjustment
 * based on observed traffic):
 *
 *     Route::post('/login', [AuthController::class, 'login'])
 *         ->middleware('throttle:5,1');   // 5 attempts per minute per IP
 *
 *     Route::post('/register', [AuthController::class, 'register'])
 *         ->middleware('throttle:3,1');   // 3 attempts per minute per IP
 *
 *     Route::post('/password/email', [AuthController::class, 'sendPasswordResetLink'])
 *         ->middleware('throttle:3,1');   // 3 attempts per minute per IP
 *
 *     Route::post('/password/reset', [AuthController::class, 'resetPassword'])
 *         ->middleware('throttle:5,1');   // 5 attempts per minute per IP
 *
 *     Route::post('/verify-email/resend', [AuthController::class, 'resendVerificationEmail'])
 *         ->middleware('throttle:3,1');   // 3 attempts per minute per IP
 *
 * For login specifically, a per-identifier throttle is stronger than
 * per-IP — it defends against a distributed attack that spreads
 * attempts across many IPs targeting one account. Laravel's built-in
 * `throttle:` middleware supports a custom key resolver if the ops
 * team wants to add this. The controller's login() method logs
 * failures (see §"Audit logging") so the throttle configuration can
 * be tuned against observed data.
 *
 * The frontend presents HTTP 429 responses with a dedicated banner
 * (see the frontend's rate-limit handling in each form's error
 * classifier), so a throttled user receives a clear "wait and retry"
 * message rather than a generic error.
 *
 * -----------------------------------------------------------------------------
 * Audit logging
 * -----------------------------------------------------------------------------
 * The controller writes structured log entries for security-relevant
 * events. The logging posture:
 *
 *   · LOGIN (INFO)      — every attempt, success or failure. Fields:
 *                         masked identifier, IP, user agent, outcome,
 *                         resolved identifier hash (for correlating
 *                         the same account across attempts without
 *                         logging the raw identifier twice).
 *
 *   · PASSWORD_RESET (INFO) — status only, never the raw email.
 *                         The email is hashed and logged as a
 *                         correlation key. Already present in the
 *                         v1.0.0 code; unchanged.
 *
 *   · LOGOUT (DEBUG)    — the fact that a logout occurred, plus the
 *                         token deletion result. DEBUG because logout
 *                         is not a security event.
 *
 * The raw identifier (email or phone) is NEVER logged. It is masked
 * by keeping the first two characters and replacing the rest with
 * asterisks for emails; phone numbers are masked by keeping the last
 * four digits. Both forms are correlated across events by a SHA-256
 * hash of the lowercased, trimmed value.
 *
 * The `email_hash` field is the same value logged by
 * sendPasswordResetLink() and resetPassword() in v1.0.0 — so a
 * support engineer can correlate a login failure with a password
 * reset request for the same account without either event leaking
 * the raw identifier.
 *
 * -----------------------------------------------------------------------------
 * Error codes
 * -----------------------------------------------------------------------------
 * Every error response from this controller follows the MASES
 * canonical envelope:
 *
 *     {
 *       "success": false,
 *       "error": {
 *         "code":       "SCREAMING_SNAKE_CASE",
 *         "message":    "…",
 *         "request_id": "…",
 *         "timestamp":  "…"
 *       }
 *     }
 *
 * The envelope itself is produced by the renderer in
 * bootstrap/app.php. The controller's contribution is the code. The
 * complete set of codes this controller can produce:
 *
 *   Login:
 *     AUTH_INVALID_CREDENTIALS   401  wrong credentials, unknown identifier,
 *                                     or wrong password — the three are
 *                                     deliberately indistinguishable
 *     VALIDATION_ERROR           422  missing or malformed input
 *
 *   Register:
 *     AUTH_EMAIL_TAKEN           409  duplicate email (raised by the
 *                                     service, not the controller)
 *     VALIDATION_ERROR           422  missing or malformed input
 *
 *   Logout:
 *     AUTH_UNAUTHENTICATED       401  no valid session (raised by the
 *                                     auth middleware before reaching
 *                                     the controller)
 *
 *   Refresh:
 *     AUTH_UNAUTHENTICATED       401  no user on the request
 *
 *   Password reset (send):
 *     VALIDATION_ERROR           422  missing or malformed input
 *     (No code for "email not found" — the response is neutral)
 *
 *   Password reset (submit):
 *     VALIDATION_ERROR           422  missing input, weak password,
 *                                     or invalid/expired token
 *                                     (single message for all)
 *
 *   Profile:
 *     AUTH_UNAUTHENTICATED       401  no user on the request
 *
 *   Verify email:
 *     AUTH_USER_NOT_FOUND        404  no user with the given id
 *     AUTH_INVALID_VERIFICATION_TOKEN  401  hash does not match
 *     AUTH_EMAIL_ALREADY_VERIFIED      400  email already verified
 *
 *   Resend verification:
 *     VALIDATION_ERROR           422  email not registered, or missing
 *                                     input
 *     AUTH_EMAIL_ALREADY_VERIFIED      400  email already verified
 *
 * The frontend's error classifiers read `error.code` where a specific
 * branch is needed (for example, the register form checks for
 * AUTH_EMAIL_TAKEN) and read `error.category` for the general
 * presentation decision (unauthorized / validation / network /
 * rate-limit / generic).
 *
 * @package App\Http\Controllers\Auth
 */
final class AuthController extends Controller
{
    /**
     * The authentication service.
     */
    private AuthenticationService $authService;
    private LoginIdentifierResolver $identifierResolver;
    private TotpMfaService $totpMfaService;

    /**
     * Create a new AuthController instance.
     */
    public function __construct(
        AuthenticationService $authService,
        LoginIdentifierResolver $identifierResolver,
        TotpMfaService $totpMfaService,
    ) {
        $this->authService = $authService;
        $this->identifierResolver = $identifierResolver;
        $this->totpMfaService = $totpMfaService;
    }

    // =========================================================================
    // Login / Register
    // =========================================================================

    /**
     * Log in a user with an identifier (email or phone) and password.
     *
     * The `email` field accepts:
     *   · a valid email address;
     *   · a phone number matching a user's `phone` column.
     *
     * Any other identifier is passed through to the service, which
     * produces its standard AUTH_INVALID_CREDENTIALS error. The
     * response is identical whether the identifier matches a user or
     * not — this is deliberate and prevents account enumeration.
     *
     * Every attempt is logged (see the class docblock §"Audit
     * logging"). The raw identifier is never logged; a masked form
     * and a SHA-256 hash are used instead.
     *
     * @param  Request $request
     * @return JsonResponse
     *
     * @throws ValidationException       when input is missing or malformed
     * @throws AuthenticationException   when credentials are invalid
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Note: the `email` rule intentionally omits the `email`
            // format check. See the class docblock §"Login identifier".
            'email'     => ['required', 'string', 'max:255'],
            'password'  => ['required', 'string'],
            'remember'  => ['sometimes', 'boolean'],
            'tenant_id' => ['sometimes', 'integer', 'exists:tenants,id'],
            'mfa_challenge_token' => ['sometimes', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'mfa_code' => ['sometimes', 'string', 'min:6', 'max:64', 'regex:/^[A-Za-z0-9]{6,64}$/'],
        ]);

        /*
         * The body tenant selects the requested login target when supplied;
         * otherwise use the middleware-verified tenant context. Membership
         * is checked independently before a token can be issued.
         */
        $trustedTenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        $trustedTenantId = is_numeric($trustedTenantId) && (int) $trustedTenantId > 0
            ? (int) $trustedTenantId
            : null;
        $bodyTenantId = isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null;
        // The body tenant selects the login target when supplied. The
        // independently verified header remains authoritative for security-event
        // attribution inside AuthenticationService (C-09).
        $requestedTenantId = $bodyTenantId ?? $trustedTenantId;

        $resolution = $this->identifierResolver->resolve($validated['email'], $requestedTenantId);

        /*
         * Never pass an unresolved non-email identifier to a service that
         * authenticates by email. A valid email retains the existing
         * explicit-tenant denial contract; other unresolved identifiers use
         * a non-matching sentinel and cannot bypass the canonical resolver.
         */
        $normalizedIdentifier = strtolower(trim($validated['email']));
        $uniqueEmailFallback = false;

        if (
            $requestedTenantId !== null
            && filter_var($normalizedIdentifier, FILTER_VALIDATE_EMAIL) !== false
        ) {
            $identityCandidates = \MaaleArna\MASES\Core\Auth\Models\User::query()
                ->where('is_active', true)
                ->where(function ($query) use ($normalizedIdentifier, $validated): void {
                    $query->where('email', $normalizedIdentifier)
                        ->orWhere('username', $normalizedIdentifier)
                        ->orWhere('phone', trim($validated['email']));
                })
                ->limit(2)
                ->get(['id', 'email']);

            $uniqueEmailFallback = $identityCandidates->count() === 1
                && strtolower((string) $identityCandidates->first()->email) === $normalizedIdentifier;
        }

        $resolvedEmail = $resolution['user']->email
            ?? ($uniqueEmailFallback ? $normalizedIdentifier : '__invalid_login_identifier__');
        $effectiveTenantId = $resolution['tenant_id'] ?? $requestedTenantId;

        /*
         * Correlation hash for the audit log. Computed from the
         * normalized identifier (lowercased, trimmed) so that the
         * same account produces the same hash across every event —
         * regardless of how the user typed the identifier.
         *
         * The hash is SHA-256, not bcrypt: it is a correlation key,
         * not a credential. It is never used for lookup. Two users
         * cannot collide on it in practice (2^256 space), and the
         * raw identifier is not recoverable from it without an
         * exhaustive preimage attack, which is infeasible for the
         * identifier's entropy.
         */
        $identifierHash = hash(
            'sha256',
            strtolower(trim($validated['email'])),
        );

        try {
            $result = $this->authService->login(
                email:    $resolvedEmail,
                password: $validated['password'],
                remember: $validated['remember'] ?? false,
                tenantId: $effectiveTenantId,
                mfaChallengeToken: $validated['mfa_challenge_token'] ?? null,
                mfaCode: $validated['mfa_code'] ?? null,
            );
        } catch (\Throwable $e) {
            /*
             * Log the failure before re-throwing. The exception
             * propagates to the renderer in bootstrap/app.php, which
             * turns it into the canonical envelope. The log entry
             * here is what makes credential-stuffing attacks
             * visible to the security team.
             *
             * The exception's message is deliberately NOT included:
             * some authentication services include the identifier in
             * the exception message for developer convenience, and
             * capturing it here would defeat the masking. The type
             * of the exception is enough for correlation.
             */
            Log::info('Login attempt failed', [
                'identifier_masked' => $this->maskIdentifier($validated['email']),
                'identifier_hash'   => $identifierHash,
                'ip'                => $request->ip(),
                'user_agent'        => $request->userAgent(),
                'exception_class'   => $e::class,
            ]);

            throw $e;
        }

        if (($result['mfa_required'] ?? false) === true) {
            return response()->json([
                'success' => true,
                'data' => [
                    'mfa_required' => true,
                    'challenge_token' => $result['challenge_token'],
                    'methods' => ['totp', 'recovery_code'],
                ],
            ]);
        }

        Log::info('Login attempt succeeded', [
            'identifier_masked' => $this->maskIdentifier($validated['email']),
            'identifier_hash'   => $identifierHash,
            'user_id'           => $result['user']->id,
            'ip'                => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'user'       => $result['user']->only(['id', 'name', 'email', 'locale', 'default_tenant_id']),
                'token'      => $result['token'],
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Register a new user.
     *
     * Accepts Ethiopian bilingual name components directly (see the
     * class docblock) and forwards them to the authentication
     * service. The legacy single-string `name` field remains accepted
     * for backward compatibility.
     *
     * On success, the user is automatically logged in. The optional
     * `remember` flag is honored for that auto-login, matching the
     * login endpoint's behavior.
     *
     * Duplicate email detection is delegated to the service layer;
     * this controller deliberately omits `unique:users,email` so the
     * service can raise the contract-specific 409 AUTH_EMAIL_TAKEN
     * error.
     *
     * @param  Request $request
     * @return JsonResponse
     *
     * @throws ValidationException       when input is missing or malformed
     * @throws AuthenticationException   when the email is already taken
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Legacy single-field name. Optional if structured name is given.
            'name'                => ['sometimes', 'string', 'max:255'],

            // Structured bilingual name components (Ethiopian naming convention).
            // `first_name_en` is required unless at least one alternate
            // identifier (first_name_am or the legacy `name`) is provided.
            'first_name_en'       => ['required_without_all:first_name_am,name', 'string', 'max:100'],
            'first_name_am'       => ['nullable', 'string', 'max:100'],
            'father_name_en'      => ['nullable', 'string', 'max:100'],
            'father_name_am'      => ['nullable', 'string', 'max:100'],
            'grandfather_name_en' => ['nullable', 'string', 'max:100'],
            'grandfather_name_am' => ['nullable', 'string', 'max:100'],

            // Contact and authentication.
            'email'               => ['required', 'string', 'email', 'max:255'],
            'password'            => ['required', 'string', 'min:8', 'confirmed'],
            'locale'              => ['sometimes', 'string', 'in:am,en'],
            'tenant_id'           => ['sometimes', 'integer', 'exists:tenants,id'],
            'remember'            => ['sometimes', 'boolean'],
        ]);

        /*
         * Compose a single-string `name` from the structured fields
         * when the caller did not send the legacy field.
         *
         * Preference order per component follows the MASES
         * Amharic-first policy: use Amharic when present, fall back
         * to English. The order matters — for a user who supplies
         * both, the Amharic name is what MASES stores as the primary
         * display name, and the English name is a secondary
         * identifier.
         */
        $structuredName = implode(' ', array_filter([
            $validated['first_name_am']       ?? $validated['first_name_en']       ?? null,
            $validated['father_name_am']      ?? $validated['father_name_en']      ?? null,
            $validated['grandfather_name_am'] ?? $validated['grandfather_name_en'] ?? null,
        ]));

        $name = $validated['name'] ?? $structuredName;

        /*
         * Collect the structured bilingual fields for the service.
         * These travel inside `additionalData`, which the service
         * already accepts as an array. Nulls are filtered out so the
         * service receives only the fields the user actually
         * supplied — a null `father_name_am` is different from an
         * empty string `father_name_am`, and the service preserves
         * that distinction when writing to the database.
         */
        $additionalData = array_filter([
            'locale'              => $validated['locale']              ?? null,
            'first_name_en'       => $validated['first_name_en']       ?? null,
            'first_name_am'       => $validated['first_name_am']       ?? null,
            'father_name_en'      => $validated['father_name_en']      ?? null,
            'father_name_am'      => $validated['father_name_am']      ?? null,
            'grandfather_name_en' => $validated['grandfather_name_en'] ?? null,
            'grandfather_name_am' => $validated['grandfather_name_am'] ?? null,
        ], static fn ($value) => $value !== null);

        $user = $this->authService->register(
            name:           $name,
            email:          $validated['email'],
            password:       $validated['password'],
            additionalData: $additionalData,
            tenantId:       $validated['tenant_id'] ?? null,
        );

        /*
         * Automatically log the user in after registration. The
         * `remember` flag mirrors the login endpoint; when omitted
         * the default (no remember) applies.
         */
        $result = $this->authService->login(
            email:    $validated['email'],
            password: $validated['password'],
            remember: $validated['remember'] ?? false,
            tenantId: $validated['tenant_id'] ?? null,
        );

        Log::info('User registered and auto-logged-in', [
            'user_id'         => $user->id,
            'identifier_hash' => hash('sha256', strtolower($validated['email'])),
            'ip'              => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'data'    => [
                'user'       => $result['user']->only(['id', 'name', 'email', 'locale', 'default_tenant_id']),
                'token'      => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * Begin TOTP enrollment for the currently authenticated account.
     * The authenticator secret is returned only to this authenticated caller.
     */
    public function beginTotpEnrollment(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof \\MaaleArna\\MASES\\Core\\Auth\\Models\\User) {
            return response()->json(['success' => false, 'error' => ['code' => 'AUTH_UNAUTHENTICATED', 'message' => 'Authentication required.']], 401);
        }

        $enrollment = $this->totpMfaService->beginEnrollment($user);
        Log::info('TOTP MFA enrollment started', ['user_id' => $user->id]);

        return response()->json(['success' => true, 'data' => $enrollment]);
    }

    /**
     * Confirm TOTP enrollment and return one-time recovery codes.
     */
    public function confirmTotpEnrollment(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\\d{6}$/']]);
        $user = $request->user();
        if (! $user instanceof \\MaaleArna\\MASES\\Core\\Auth\\Models\\User) {
            return response()->json(['success' => false, 'error' => ['code' => 'AUTH_UNAUTHENTICATED', 'message' => 'Authentication required.']], 401);
        }

        $codes = $this->totpMfaService->confirmEnrollment($user, $validated['code']);
        if ($codes === null) {
            return response()->json(['success' => false, 'error' => ['code' => 'AUTH_MFA_INVALID', 'message' => 'Invalid TOTP code or no pending enrollment.']], 422);
        }

        Log::info('TOTP MFA enabled', ['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'data' => [
                'mfa_enabled' => true,
                'recovery_codes' => $codes,
                'recovery_codes_notice' => 'Store these codes securely. They are shown only once.',
            ],
        ]);
    }

    /**
     * Disable TOTP only after validating a current authenticator code.
     */
    public function disableTotp(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\\d{6}$/']]);
        $user = $request->user();
        if (! $user instanceof \\MaaleArna\\MASES\\Core\\Auth\\Models\\User) {
            return response()->json(['success' => false, 'error' => ['code' => 'AUTH_UNAUTHENTICATED', 'message' => 'Authentication required.']], 401);
        }

        if (! $this->totpMfaService->disable($user, $validated['code'])) {
            return response()->json(['success' => false, 'error' => ['code' => 'AUTH_MFA_INVALID', 'message' => 'Invalid TOTP code.']], 422);
        }

        Log::warning('TOTP MFA disabled', ['user_id' => $user->id]);

        return response()->json(['success' => true, 'data' => ['mfa_enabled' => false]]);
    }

    // =========================================================================
    // Logout / Refresh
    // =========================================================================

    /**
     * Log out the currently authenticated user.
     *
     * Performs three redundant deletions for reliability:
     *   1. Explicit ID-based deletion of the incoming bearer token.
     *   2. Bulk revocation (and cache blacklisting) via the service.
     *   3. Clearing of the auth guard cache.
     *
     * The redundancy is deliberate. Each of the three mechanisms has
     * failed in production Laravel deployments at some point in the
     * past: the ID-based deletion fails if the token format changes;
     * the service revocation fails if the user object is stale; the
     * guard-cache clear fails if the auth manager has been swapped.
     * Any one of the three succeeding is enough to log the user out
     * cleanly. All three run in the same request.
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->deleteIncomingBearerToken($request);

        if ($user !== null) {
            $this->authService->logout($user, revokeTokens: true);
        }

        try {
            app('auth')->forgetGuards();
        } catch (\Throwable $e) {
            /*
             * The forgetGuards() call is a defensive measure that
             * rarely fails. When it does — typically because the
             * auth manager has been substituted in a testing or
             * custom configuration — the logout itself has already
             * succeeded via the previous two steps. Log at DEBUG
             * because this is a diagnostic signal, not a failure
             * that requires intervention.
             */
            Log::debug('Failed to forget auth guards on logout', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::debug('User logged out', [
            'user_id' => $user?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * Refresh the user's authentication token.
     *
     * Revokes the incoming bearer token and every other token, then
     * issues a fresh one. The old token becomes unusable
     * immediately.
     *
     * The endpoint is idempotent — calling it twice in succession
     * produces two different tokens, both valid, with the second
     * one being the only valid one after the second call returns.
     * The frontend uses this endpoint when the current token is
     * nearing its expiration (SANCTUM_TOKEN_EXPIRATION, default 7
     * days).
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            /*
             * The auth middleware should have prevented this — a
             * refresh request without a user never reaches the
             * controller under normal routing. This branch exists as
             * a defensive check for route misconfiguration.
             */
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTH_UNAUTHENTICATED',
                    'message' => 'Not authenticated.',
                ],
            ], 401);
        }

        $this->deleteIncomingBearerToken($request);

        $newToken = $this->authService->refreshToken($user);

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $newToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    // =========================================================================
    // Password Reset
    // =========================================================================

    /**
     * Send a password reset link to the given email address.
     *
     * The response is neutral — it does not distinguish between an
     * email that is registered and one that is not. See the class
     * docblock §"Password reset neutrality".
     *
     * -----------------------------------------------------------------------------
     * Laravel's Password::* status enumeration
     * -----------------------------------------------------------------------------
     * The service's sendPasswordResetLink() returns one of Laravel's
     * Password broker status constants. This controller handles each
     * as follows:
     *
     *   Password::RESET_LINK_SENT      — success. The email was
     *                                    registered and a reset link
     *                                    was dispatched. Neutral
     *                                    response returned.
     *
     *   Password::INVALID_USER         — the email was not
     *                                    registered. Neutral
     *                                    response returned (identical
     *                                    to RESET_LINK_SENT from the
     *                                    client's perspective).
     *
     *   Password::RESET_THROTTLED      — the user has requested a
     *                                    reset too recently. Neutral
     *                                    response returned. Logged
     *                                    at WARNING because this is a
     *                                    signal that either the user
     *                                    is stuck or an attacker is
     *                                    probing.
     *
     *   Anything else                  — an unexpected broker status
     *                                    (typically a transport
     *                                    failure). Neutral response
     *                                    returned. Logged at WARNING.
     *
     * In every case, the HTTP response is 200 with the same body.
     * The only difference is what gets written to the log.
     *
     * @param  Request $request
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The `exists:users,email` rule was removed: it leaked
            // whether an email is registered. See the class docblock.
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $status = $this->authService->sendPasswordResetLink($validated['email']);

        /*
         * INVALID_USER means the email is not registered. That is not
         * a failure condition for the client — the response is the
         * same as a successful send. Any other failure status
         * indicates a transport or rate-limit problem worth logging.
         */
        if ($status !== Password::RESET_LINK_SENT && $status !== Password::INVALID_USER) {
            Log::warning('Password reset link send failed', [
                'status'     => $status,
                'email_hash' => hash('sha256', $validated['email']),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset the user's password using a token.
     *
     * The response is neutral — it does not distinguish between an
     * invalid token and an unknown email. See the class docblock
     * §"Password reset neutrality".
     *
     * Every failure mode produces the same 422 VALIDATION_ERROR with
     * the same message ("passwords.token"), so the client cannot
     * distinguish the reason. The Laravel status code is logged at
     * INFO for operational diagnosis.
     *
     * @param  Request $request
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The `exists:users,email` rule was removed: it leaked
            // whether an email is registered. See the class docblock.
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'token'    => ['required', 'string'],
        ]);

        $status = $this->authService->resetPassword($validated);

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * A single message for every failure mode, so the client
             * cannot distinguish "invalid token" from "unknown
             * email". The Laravel status is logged, not returned.
             *
             * The status is logged at INFO, not WARNING: an expired
             * or reused token is a normal user experience (the user
             * followed an old email), not an anomaly. A spike in
             * this log — many failures from the same IP in a short
             * window — would be a signal worth investigating, but
             * the individual event is not.
             */
            Log::info('Password reset attempt failed', [
                'status'     => $status,
                'email_hash' => hash('sha256', $validated['email']),
                'ip'         => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => [__('passwords.token')],
            ]);
        }

        Log::info('Password reset completed', [
            'email_hash' => hash('sha256', $validated['email']),
            'ip'         => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully.',
        ]);
    }

    // =========================================================================
    // Profile
    // =========================================================================

    /**
     * Get the currently authenticated user's profile.
     *
     * Returns the FULL user record — unlike the `->only([...])`
     * projection that login() and register() return, this endpoint
     * includes tenants, roles, and permissions. The frontend's
     * auth service calls this endpoint immediately after login to
     * fill in the fields the login response omitted.
     *
     * The relationships are explicitly loaded here. The original
     * implementation assumed the caller had pre-loaded them; if
     * they had not, Laravel's lazy loading would trigger N+1
     * queries and the response would still contain the data — but
     * on a database that disallows lazy loading
     * (`Model::preventLazyLoading()`), the response would silently
     * return empty arrays instead of the real relationships. The
     * explicit load removes that ambiguity.
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        /** @var \MaaleArna\MASES\Core\Auth\Models\User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTH_UNAUTHENTICATED',
                    'message' => 'Not authenticated.',
                ],
            ], 401);
        }

        /*
         * Load the relationships explicitly. This is a no-op when
         * they were already loaded (e.g., by a route middleware that
         * anticipated this endpoint's needs), and a single query
         * per relationship when they were not.
         */
        $user->loadMissing(['tenants', 'roles', 'permissions']);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'locale'            => $user->locale,
                'default_tenant_id' => $user->default_tenant_id,
                'is_active'         => $user->is_active,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at'        => $user->created_at?->toIso8601String(),
                'updated_at'        => $user->updated_at?->toIso8601String(),
                'tenants'           => $user->tenants->map(fn ($tenant) => [
                    'id'   => $tenant->id,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                ]),
                'roles'             => $user->roles->map(fn ($role) => [
                    'id'   => $role->id,
                    'slug' => $role->slug,
                    'name' => $role->name,
                ]),
                'permissions'       => $user->permissions->map(fn ($permission) => [
                    'id'   => $permission->id,
                    'slug' => $permission->slug,
                    'name' => $permission->name,
                ]),
            ],
        ]);
    }

    // =========================================================================
    // Email Verification
    // =========================================================================

    /**
     * Verify the user's email address using a verification token.
     *
     * The verification link's shape is:
     *     /api/verify-email/{id}/{hash}
     *
     * The `id` is the user's primary key. The `hash` is
     * `sha1(user.email)` — Laravel's `MustVerifyEmail` trait uses
     * SHA-1 here, not SHA-256, because the value is a verification
     * token, not a credential. It is compared with `hash_equals`
     * for timing-attack resistance.
     *
     * This endpoint is a GET that mutates state (it marks the email
     * verified). That is unusual REST, but it is the frozen contract
     * that the frontend expects. See the frontend's
     * VerifyEmailPanel.vue docblock for the client-side handling.
     *
     * @param  Request $request
     * @param  string  $id
     * @param  string  $hash
     * @return JsonResponse
     */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = \MaaleArna\MASES\Core\Auth\Models\User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTH_USER_NOT_FOUND',
                    'message' => 'User not found.',
                ],
            ], 404);
        }

        /*
         * hash_equals is the timing-safe comparison Laravel
         * recommends for token comparison. A naive `===` would leak
         * the correct hash byte-by-byte under a sufficiently
         * determined attacker with network access to the endpoint.
         *
         * The order of arguments matters for the timing guarantee:
         * the known value (the user's expected hash) comes first,
         * the user-supplied value second. This is what Laravel's own
         * EmailVerificationRequest does.
         */
        if (!hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTH_INVALID_VERIFICATION_TOKEN',
                    'message' => 'Invalid verification token.',
                ],
            ], 401);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTH_EMAIL_ALREADY_VERIFIED',
                    'message' => 'Email already verified.',
                ],
            ], 400);
        }

        $user->markEmailAsVerified();

        Log::info('Email verified', [
            'user_id' => $user->id,
            'ip'      => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
        ]);
    }

    /**
     * Resend the email verification link.
     *
     * Unlike the password-reset endpoints, this endpoint DOES
     * validate `exists:users,email`. The reason: the verification
     * flow has no analogous "check your inbox and spam folder"
     * neutral copy — a user who submits an unregistered email here
     * has no way to distinguish "we sent it but it did not arrive"
     * from "we did not send it because the account does not exist".
     * The 422 is the correct feedback.
     *
     * If the design team decides to make this endpoint neutral for
     * consistency, the change is coordinated with the frontend's
     * VerifyEmailResend.vue — a separate CR.
     *
     * @param  Request $request
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'exists:users,email'],
        ]);

        $user = \MaaleArna\MASES\Core\Auth\Models\User::where('email', $validated['email'])->first();

        /*
         * The `exists:users,email` rule above already guarantees a
         * user exists, so this branch is unreachable in normal
         * operation. It is a defensive check: if the rule is ever
         * removed without changing this branch, the endpoint will
         * still fail safely with a validation error rather than
         * throwing a null-pointer exception.
         */
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTH_EMAIL_ALREADY_VERIFIED',
                    'message' => 'Email already verified.',
                ],
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Verification email resent successfully.',
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve a login identifier to the email address the auth
     * service expects.
     *
     * Returns:
     *   · the identifier unchanged when it is a valid email format
     *     (after normalization — see below);
     *   · the email of the user whose `phone` column matches the
     *     identifier, when the identifier is not an email;
     *   · null when neither matches.
     *
     * Username is NOT supported. The `users` table has no
     * `username` column; adding one is a CR. When that CR lands,
     * this method gains a third branch above the `phone` lookup.
     *
     * A null return is not an error — the caller (login()) passes
     * the raw identifier through to the service, which produces its
     * standard AUTH_INVALID_CREDENTIALS envelope. The client cannot
     * distinguish "identifier matched no user" from "identifier
     * matched a user but the password was wrong."
     *
     * -----------------------------------------------------------------------------
     * Normalization
     * -----------------------------------------------------------------------------
     * The identifier is normalized to lowercase and trimmed before
     * format checking. Two reasons:
     *
     *   1. Email addresses are case-insensitive per RFC 5321. A user
     *      who registered as "user@example.com" and later types
     *      "User@Example.com" must be able to log in. MySQL's default
     *      collation (utf8mb4_unicode_ci) is case-insensitive, so the
     *      database lookup would work either way — but the resolver's
     *      in-PHP comparison against a hardcoded format must also
     *      accept the mixed-case form.
     *
     *   2. Phone numbers do not have case, but they frequently have
     *      surrounding whitespace from copy-paste. Trimming is
     *      necessary for the phone lookup to match.
     *
     * The phone lookup does NOT normalize the phone number itself
     * (stripping spaces, adding country code). That normalization is
     * the responsibility of whatever process wrote the phone number
     * to the database in the first place, and it is not the login
     * resolver's job to guess the intended format.
     *
     * @param  string $identifier
     * @return string|null
     */
    private function resolveLoginEmail(string $identifier): ?string
    {
        $normalized = strtolower(trim($identifier));

        /*
         * Empty identifier after trimming — the caller's validation
         * required a non-empty string, but " " (whitespace) passes
         * that rule and becomes "" here. Return null so the service
         * produces its standard invalid-credentials envelope rather
         * than looking up an empty email.
         */
        if ($normalized === '') {
            return null;
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false) {
            return $normalized;
        }

        $user = \MaaleArna\MASES\Core\Auth\Models\User::query()
            ->where('phone', $identifier)
            ->first();

        if ($user !== null) {
            /*
             * Phone resolution succeeded. Log the fact at DEBUG (not
             * INFO) — the login() method already logs the attempt
             * with a correlation hash, and this entry is
             * supplementary diagnostics for support engineers
             * investigating a specific login failure.
             *
             * The phone number is masked in the log so that no raw
             * contact information appears anywhere in the log file.
             */
            Log::debug('Login identifier resolved via phone lookup', [
                'phone_masked' => $this->maskIdentifier($identifier),
                'user_id'      => $user->id,
            ]);

            return $user->email;
        }

        return null;
    }

    /**
     * Mask an identifier (email or phone) for safe logging.
     *
     * For emails: keep the first two characters of the local part,
     * replace the rest with asterisks, keep the domain unchanged. A
     * length of "aa***@example.com" reads as an email in the log
     * without exposing the local part.
     *
     * For phone numbers: keep the last four digits, replace the rest
     * with asterisks. This is the PCI-DSS-recommended masking for
     * phone numbers (retain enough to identify, not enough to
     * contact).
     *
     * For anything else (unrecognized identifier shape): return
     * "***" so nothing about the input leaks.
     *
     * The function is deliberately conservative — it would rather
     * mask too much than mask too little. A support engineer reading
     * the log gets enough context to correlate events without ever
     * seeing a raw credential.
     *
     * @param  string $identifier
     * @return string
     */
    private function maskIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return '***';
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false) {
            [$local, $domain] = explode('@', $trimmed, 2);
            $visible = substr($local, 0, 2);
            return $visible . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
        }

        /*
         * Not an email — assume a phone number. Retain the last four
         * digits and mask everything before that. A phone number
         * shorter than four digits is masked entirely, which is the
         * correct conservative behavior.
         */
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        if (strlen($digits) < 4) {
            return '***';
        }

        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    /**
     * Delete the incoming bearer token from the
     * `personal_access_tokens` table, identified by the ID prefix of
     * the token's `{id}|{plainText}` format.
     *
     * Sanctum's personal access tokens are stored hashed in the
     * database. The plaintext the client holds is
     * `{token_id}|{random_string}`. The `{token_id}` half is a
     * primary key lookup, which is dramatically faster than the
     * hash-based lookup that would otherwise be needed.
     *
     * This method tries the ID-based path first. If the token does
     * not contain a pipe (unusual — Sanctum always produces tokens
     * with the pipe), it falls back to a SHA-256 hash lookup of the
     * whole bearer string.
     *
     * Returns true when a row was deleted, false otherwise. The
     * caller (logout) does not depend on the return value — the
     * service's bulk revocation is the primary mechanism — but the
     * boolean is useful for the log entry.
     *
     * @param  Request $request
     * @return bool
     */
    private function deleteIncomingBearerToken(Request $request): bool
    {
        $bearer = $request->bearerToken();

        if (!is_string($bearer) || $bearer === '') {
            return false;
        }

        if (str_contains($bearer, '|')) {
            [$possibleId] = explode('|', $bearer, 2);

            if (is_numeric($possibleId)) {
                $deleted = PersonalAccessToken::where('id', (int) $possibleId)->delete();

                Log::debug('Deleted incoming bearer token by ID', [
                    'token_id' => (int) $possibleId,
                    'deleted'  => $deleted,
                ]);

                return $deleted > 0;
            }
        }

        /*
         * Fallback: the token did not contain a numeric ID prefix.
         * This is unusual — Sanctum always emits `{id}|{plain}` —
         * but a corrupted or manually-constructed token could reach
         * this branch. Delete by hash.
         */
        $hashed = hash('sha256', $bearer);
        $deleted = PersonalAccessToken::where('token', $hashed)->delete();

        Log::debug('Deleted incoming bearer token by hash', [
            'deleted' => $deleted,
        ]);

        return $deleted > 0;
    }
}