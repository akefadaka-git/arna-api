<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MASES API Routes
|--------------------------------------------------------------------------
|
| This file defines every HTTP route served by the MASES central API.
| Routes are grouped into four tiers, each with a distinct trust model:
|
|   1. Public routes        – no authentication required.
|   2. Authenticated routes – require a valid Sanctum token.
|   3. Diagnostic routes   – authenticated, but explicitly NOT tenant-enforced
|                             (reports the tenant context for debugging).
|   4. Tenant-scoped routes – require authentication AND tenant membership.
|
| Every response follows the MASES standard JSON envelope:
|
|     Success:  { "success": true,  "data": ... }
|     Failure:  { "success": false, "error": { "code": ..., "message": ... } }
|
| Middleware aliases used below:
|   - auth:sanctum         – Laravel Sanctum bearer-token authentication.
|   - tenant.membership    – verifies the authenticated user belongs to the
|                            resolved tenant (see TenantMembershipMiddleware).
|   - auth.permission:*    – requires a specific permission slug.
|   - auth.role:*          – requires a specific role slug.
|   - auth.application:*   – requires application access.
|
| -----------------------------------------------------------------------------
| Fallback behaviour
| -----------------------------------------------------------------------------
| The fallback route throws `NotFoundHttpException` instead of returning its
| own JSON envelope. This ensures that the canonical error renderer in
| `bootstrap/app.php` produces the response, so every 404 carries the exact
| same shape as every other error — including `request_id` and `timestamp`.
|
| -----------------------------------------------------------------------------
| Debug mode
| -----------------------------------------------------------------------------
| This file does not control debug mode. Debug mode is controlled by the
| `APP_DEBUG` environment variable in api.maalearna.com.et/.env:
|
|     APP_DEBUG=true    — full stack traces in responses and logs
|     APP_DEBUG=false   — sanitized errors, no stack traces
|
| The MASES error renderer (bootstrap/app.php) reads APP_DEBUG and shapes
| the error envelope accordingly. The `request_id` and `timestamp` fields
| are present in both modes; only the stack trace and internal detail
| differ. In development, keep APP_DEBUG=true so failed requests produce
| actionable diagnostics in storage/logs/laravel.log. In production, set
| it to false.
|
| This file preserves the diagnostic routes (/health and /tenant-context)
| in both modes — they are safe to leave in production and are the first
| tools to reach for when a request behaves unexpectedly.
|
| -----------------------------------------------------------------------------
| Change Request CR-003a
| -----------------------------------------------------------------------------
| The `/health` and `/user` endpoints were previously returning their payload
| at the top level, violating the envelope contract declared above. Both
| responses are now wrapped in `{ "success": true, "data": { ... } }`, so
| consumers can rely on a single, consistent shape across every endpoint.
|
| -----------------------------------------------------------------------------
| Revision history
| -----------------------------------------------------------------------------
| 1.3.2  — /api/launcher route registered.
|
|          Symptom: the launcher page rendered its Amharic empty state
|          ("ምንም ተደራሽ አፕሊኬሽኖች የሉም") for every user, including platform
|          administrators who hold app.* access permissions. Browser
|          Network tab showed:
|
|              GET https://api.maalearna.com.et/api/launcher
|              → HTTP/2 404
|              { "code": "ROUTE_NOT_FOUND", ... }
|
|          Cause: the ApplicationController
|          (app/Http/Controllers/ApplicationController.php) existed and
|          the frontend service (src/features/launcher/service.ts)
|          fetched GET /api/launcher — but no route was ever registered
|          in this file for the endpoint. Every launcher request hit the
|          fallback and returned 404 ROUTE_NOT_FOUND. The controller was
|          delivered in isolation and never wired into the route table.
|
|          Fix: register the route inside the tenant-scoped group,
|          immediately before the file-management block. The
|          placement is deliberate — the controller assumes both an
|          authenticated user (auth:sanctum) and a resolved,
|          member-verified tenant (tenant.membership). It carries no
|          additional middleware because the per-application
|          authorization varies by row and is enforced inside the
|          controller: the caller's `app.{slug}.access` permission is
|          checked per registered application, and the response
|          contains only the rows whose access permission the caller
|          holds.
|
|          No other change to this file. Every route above and below
|          the new entry is byte-for-byte identical to 1.3.1.
|
| 1.3.1  — /api/user permissions list fix.
|
|          The `permissions` field previously read `$user->permissions` —
|          the relation that queries `user_has_permissions`, i.e. ONLY
|          direct per-user grants. In the MASES deployment the
|          `user_has_permissions` table is deliberately empty; every
|          permission is granted via roles (`user_has_roles` →
|          `role_has_permissions`). The response therefore returned
|          `"permissions": []` for every user, even those with full
|          platform-admin grants. The frontend's permission gate then
|          denied every permission-gated route, producing a spurious
|          Amharic 403 on /wallets and every other domain workspace.
|
|          The fix merges direct grants and role-derived grants into one
|          deduplicated, slug-only list. The User model's `permissions`
|          relation is left untouched — its documented contract ("directly
|          assigned") is correct; only this endpoint's consumption of it
|          was wrong. `roles.permissions` is eager-loaded via loadMissing
|          to avoid an N+1 on the roles collection.
|
|          Response shape is unchanged: `permissions` remains a flat
|          array of slug strings. Clients that already tolerate an empty
|          array continue to work; clients that filter by slug now see
|          the correct set.
|
| 1.3.0  — prior version (CR-003a envelope fix).
|
| @package MASES
| @version 1.3.2
*/

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PhoneVerificationController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\TenantContextController;
use App\Http\Controllers\PublicCertificateVerificationController;
use App\Http\Controllers\PersonController;
use MaaleArna\MASES\Core\Auth\Models\User;

// =============================================================================
// 1. Public Routes – No authentication required
// =============================================================================

/**
 * Health Check
 *
 * Used by monitoring tools, load balancers, and readiness probes.
 * Deliberately unauthenticated and tenant-independent.
 *
 * Response follows the MASES envelope:
 *   { "success": true, "data": { status, timestamp, version, environment } }
 */
Route::get('/health', static function (): JsonResponse {
    return response()->json([
        'success' => true,
        'data'    => [
            'status'      => 'ok',
            'timestamp'   => now()->toIso8601String(),
            'version'     => '1.2.0',
            'environment' => app()->environment(),
        ],
    ]);
})->name('api.health');


// -----------------------------------------------------------------------------
// Public document verification
// -----------------------------------------------------------------------------

/**
 * Verify a public share-certificate reference without exposing shareholder
 * identity or tenant-scoped data. Rate limited to reduce reference guessing.
 */
Route::post(
    '/public/document-verifications/verify',
    [PublicCertificateVerificationController::class, 'verify']
)->middleware('throttle:10,1')
 ->name('api.public.document-verifications.verify');

// -----------------------------------------------------------------------------
// Authentication
// -----------------------------------------------------------------------------

/**
 * Login
 *
 * Authenticates a user with email/password. If `tenant_id` is provided,
 * membership is validated before the token is issued.
 */
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.auth.login');

/**
 * Register
 *
 * Creates a new user account, optionally attaches it to a tenant, and
 * returns a token for automatic login. Email uniqueness is enforced.
 */
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1')
    ->name('api.auth.register');

// -----------------------------------------------------------------------------
// Password Reset
// -----------------------------------------------------------------------------

/**
 * Request Password Reset Link
 *
 * Sends a password reset link to the given email address. Always returns
 * a neutral response to avoid leaking whether the email exists.
 */
Route::post('/password/email', [AuthController::class, 'sendPasswordResetLink'])
    ->middleware('throttle:3,1')
    ->name('api.auth.password.email');

/**
 * Reset Password
 *
 * Validates the reset token and updates the user's password.
 */
Route::post('/password/reset', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1')
    ->name('api.auth.password.reset');

// -----------------------------------------------------------------------------
// Email Verification
// -----------------------------------------------------------------------------

/**
 * Verify Email Address
 *
 * Confirms a user's email using the SHA1 hash of the email address,
 * which is included in the verification link sent to the user.
 */
Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->name('api.auth.verify-email');

/**
 * Resend Verification Email
 *
 * Sends a fresh verification link to an unverified user.
 */
Route::post('/verify-email/resend', [AuthController::class, 'resendVerificationEmail'])
    ->middleware('throttle:3,1')
    ->name('api.auth.verify-email.resend');

// =============================================================================
// 2 & 3. Authenticated Routes – Require a valid Sanctum token
// =============================================================================
//
// This group contains:
//   - Authentication management (logout, profile, token refresh).
//   - The current-user summary endpoint.
//   - The tenant-context diagnostic endpoint, which is deliberately NOT
//     protected by `tenant.membership`. It reports the resolved tenant
//     and whether the user is a member, so callers can debug "why can't I
//     access tenant X?" without ever leaking data from other tenants.
//
// Membership enforcement happens on the tenant-scoped group (§4) via the
// `tenant.membership` middleware, NOT here.
// =============================================================================

Route::middleware(['auth:sanctum'])->group(static function (): void {

    // -------------------------------------------------------------------------
    // Multi-factor Authentication
    // -------------------------------------------------------------------------

    Route::post('/mfa/totp/enroll', [AuthController::class, 'beginTotpEnrollment'])
        ->middleware('throttle:3,1')
        ->name('api.auth.mfa.totp.enroll');

    Route::post('/mfa/totp/confirm', [AuthController::class, 'confirmTotpEnrollment'])
        ->middleware('throttle:5,1')
        ->name('api.auth.mfa.totp.confirm');

    Route::post('/mfa/totp/disable', [AuthController::class, 'disableTotp'])
        ->middleware('throttle:5,1')
        ->name('api.auth.mfa.totp.disable');

    Route::post('/phone/verification/request', [PhoneVerificationController::class, 'requestCode'])
        ->middleware('throttle:3,10')
        ->name('api.auth.phone-verification.request');

    Route::post('/phone/verification/confirm', [PhoneVerificationController::class, 'confirmCode'])
        ->middleware('throttle:5,1')
        ->name('api.auth.phone-verification.confirm');

    // -------------------------------------------------------------------------
    // Authentication Management
    // -------------------------------------------------------------------------

    /**
     * Logout
     *
     * Revokes all tokens belonging to the authenticated user.
     */
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('api.auth.logout');

    /**
     * Profile
     *
     * Returns the full user profile: identity, tenants, roles, permissions.
     */
    Route::get('/profile', [AuthController::class, 'profile'])
        ->name('api.auth.profile');

    /**
     * Refresh Token
     *
     * Revokes the current token and issues a new one.
     */
    Route::post('/token/refresh', [AuthController::class, 'refreshToken'])
        ->name('api.auth.token.refresh');

    // -------------------------------------------------------------------------
    // Current User Summary
    // -------------------------------------------------------------------------

    /**
     * Authenticated User Summary
     *
     * Lightweight endpoint returning basic identity plus tenant / role /
     * permission slugs. Useful for frontend authorisation heuristics.
     *
     * Response follows the MASES envelope:
     *   {
     *     "success": true,
     *     "data": {
     *       id, name, email, locale, default_tenant_id,
     *       tenants, roles, permissions
     *     }
     *   }
     *
     * The `name` attribute is provided by the `getNameAttribute()` accessor
     * added in Phase 1 Amendment A4, which projects the composed Ethiopian
     * full name (Amharic-first, English fallback) from the six underlying
     * name columns.
     *
     * -----------------------------------------------------------------------------
     * PERMISSIONS — Revision 1.3.1
     * -----------------------------------------------------------------------------
     * The `permissions` field is the union of:
     *
     *   1. Permissions granted directly to the user
     *      (`user_has_permissions`, exposed via `$user->permissions`).
     *
     *   2. Permissions granted to any role the user holds
     *      (`user_has_roles` → `role_has_permissions`, exposed via
     *      `$user->roles[*]->permissions`).
     *
     * Both paths are needed. In the current MASES deployment, path (1) is
     * always empty — the platform grants permissions exclusively through
     * roles — but path (1) remains in the merge so that a future direct
     * grant (an executive override, an emergency elevation) is reflected
     * without a code change.
     *
     * The result is deduplicated and re-indexed so the JSON output is a
     * plain array of unique slug strings with no gaps.
     *
     * WHY THIS IS DONE HERE AND NOT IN THE MODEL:
     * The User model's `permissions()` relation is documented as "directly
     * assigned" and is used correctly elsewhere (the AuthorizationService
     * tests it explicitly as part of `hasPermission()`, falling through to
     * the roles path on miss). Changing the relation to merge both paths
     * would break that explicit fall-through. The union is a presentation
     * concern of this endpoint, so it lives at the endpoint.
     *
     * The roles' permissions are eager-loaded via `loadMissing` before the
     * merge, so the endpoint is two queries rather than one-plus-N.
     */
    Route::get('/user', static function (Request $request): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        /*
         * Eager-load tenants, roles-with-permissions, and direct
         * permissions in one call. `loadMissing` is a no-op for relations
         * a route middleware has already loaded; otherwise it adds a
         * single query per relation and one for the nested roles.
         * permissions. This replaces the implicit lazy loading that the
         * previous version triggered on `$user->tenants` and
         * `$user->roles`, and adds `roles.permissions` (the new
         * dependency introduced by revision 1.3.1).
         */
        $user->loadMissing(['tenants', 'roles.permissions', 'permissions']);

        /*
         * Merge direct grants with role-derived grants, deduplicate by
         * slug, and re-index. `pluck('slug')` returns a Collection;
         * `merge(...)` concatenates; `unique()` drops duplicates;
         * `values()` re-indexes so the JSON output has no gaps.
         *
         * The nesting `$user->roles->flatMap->permissions->pluck('slug')`
         * is Laravel Collection higher-order syntax equivalent to:
         *
         *     $user->roles
         *         ->flatMap(fn ($role) => $role->permissions)
         *         ->pluck('slug')
         *
         * It works because `roles.permissions` was eager-loaded above,
         * so no lazy loading fires inside the closure.
         */
        $permissionSlugs = $user->permissions
            ->pluck('slug')
            ->merge($user->roles->flatMap->permissions->pluck('slug'))
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'locale'            => $user->locale,
                'default_tenant_id' => $user->default_tenant_id,
                'tenants'           => $user->tenants->pluck('slug'),
                'roles'             => $user->roles->pluck('slug'),
                'permissions'       => $permissionSlugs,
            ],
        ]);
    })->name('api.user');

    // -------------------------------------------------------------------------
    // Diagnostic: Tenant Context
    // -------------------------------------------------------------------------
    //
    // Intentionally OUTSIDE the `tenant.membership` group.
    //
    // Why:
    //   - `tenant.membership` returns 403 for non-members, which would make
    //     the diagnostic endpoint useless for debugging membership issues.
    //   - `tenant.membership` also returns 400 when no tenant is resolved,
    //     but this endpoint must return 200 with `tenant_id: null` in that
    //     case so clients can distinguish "no tenant" from "forbidden".
    //
    // Security:
    //   - Still requires `auth:sanctum`, so only authenticated users can
    //     inspect their own tenant context.
    //   - Never exposes data from other tenants; only reports the tenant
    //     resolved for the current request and the caller's own membership.
    //
    // The controller (`TenantContextController`) encapsulates the resolution
    // logic, the `is_member` computation, and the `resolution_source`
    // detection. See its class documentation for the full contract.
    // -------------------------------------------------------------------------

    Route::get('/tenant-context', [TenantContextController::class, 'show'])
        ->name('api.tenant.context');
});

// =============================================================================
// 4. Tenant-Scoped Routes – Require authentication AND tenant membership
// =============================================================================
//
// Every route in this group is protected by two middlewares:
//   - `auth:sanctum`      – establishes the authenticated user.
//   - `tenant.membership` – verifies that the user belongs to the tenant
//                            resolved by the global TenantContextMiddleware.
//
// Failure modes:
//   - No tenant resolved             → 400 TENANT_REQUIRED.
//   - Tenant resolved, user is not   → 403 TENANT_ACCESS_DENIED.
//     a member
//   - Permission check fails         → 403 AUTH_PERMISSION_DENIED.
//   - Role check fails               → 403 AUTH_ROLE_DENIED.
//   - Application access fails       → 403 AUTH_APPLICATION_DENIED.
//
// All denials are additionally persisted as `security_events` rows via the
// `AuthorizationFailureEvent` pipeline (see LogAuthorizationFailureListener).
// =============================================================================

Route::middleware(['auth:sanctum', 'tenant.membership'])->group(static function (): void {

    // -------------------------------------------------------------------------
    // Launcher — the caller's launchable application catalog
    // -------------------------------------------------------------------------
    //
    // Returns the applications from the platform_applications registry
    // that are (a) active, (b) frontend-ready, and (c) reachable by the
    // authenticated user — i.e. the caller holds the matching
    // `app.{slug}.access` permission (granted directly or via a role).
    //
    // The permission filter runs in the controller, not as route
    // middleware, because the required permission varies per row. See
    // ApplicationController's class docblock for the filter semantics
    // and the reasoning behind the PHP-side check.
    //
    // No additional middleware is applied. Application-level
    // authorization is enforced inside the controller.
    //
    // Route name: api.launcher. The frontend's launcher feature
    // (src/features/launcher/service.ts) fetches this endpoint after
    // login and after the shell mounts.
    //
    // Added in revision 1.3.2 — see the file header for the full
    // diagnosis of the missing route.
    // -------------------------------------------------------------------------

    Route::get('/launcher', [ApplicationController::class, 'index'])
        ->name('api.launcher');

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // People Profile — explicit tenant visibility and tenant-scoped mutations
    // -------------------------------------------------------------------------

    Route::prefix('/people')->name('api.people.')->group(static function (): void {
        Route::get('/', [PersonController::class, 'index'])->name('index');
        Route::post('/', [PersonController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('store');
        Route::get('/{publicId}', [PersonController::class, 'show'])
            ->whereUuid('publicId')
            ->name('show');
        Route::get('/{publicId}/relationships', [PersonController::class, 'relationships'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('relationships');
        Route::put('/{publicId}', [PersonController::class, 'update'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('update');
    });

    // File Management
    // -------------------------------------------------------------------------

    Route::prefix('/files')->name('api.files.')->group(static function (): void {

        /** Upload a file (multipart/form-data). */
        Route::post('/upload', [FileController::class, 'upload'])
            ->name('upload');

        /** List files for the current tenant (paginated, filterable). */
        Route::get('/', [FileController::class, 'index'])
            ->name('index');

        /** Get file metadata (no content). */
        Route::get('/{id}', [FileController::class, 'show'])
            ->name('show');

        /** Download file content (streamed). */
        Route::get('/{id}/download', [FileController::class, 'download'])
            ->name('download');

        /** Update file metadata or replace content (creates a new version). */
        Route::put('/{id}', [FileController::class, 'update'])
            ->name('update');

        /** Soft delete a file (recoverable). */
        Route::delete('/{id}', [FileController::class, 'destroy'])
            ->name('destroy');

        /** Permanently delete a file (hard delete). */
        Route::delete('/{id}/force', [FileController::class, 'forceDestroy'])
            ->name('force-destroy');

        /** Restore a soft-deleted file. */
        Route::post('/{id}/restore', [FileController::class, 'restore'])
            ->name('restore');

        /** Get version history of a file. */
        Route::get('/{id}/versions', [FileController::class, 'versions'])
            ->name('versions');

        /** Get the latest version of a file. */
        Route::get('/{id}/latest', [FileController::class, 'latestVersion'])
            ->name('latest');
    });
});

// =============================================================================
// 5. Fallback – Delegate to the canonical error renderer
// =============================================================================
//
// The fallback does NOT return its own JSON envelope. Instead, it throws a
// `NotFoundHttpException` that is caught by the unified renderable callback
// in `bootstrap/app.php`. This guarantees the response shape is identical to
// every other error path:
//
//     {
//       "success": false,
//       "error": {
//         "code":       "ROUTE_NOT_FOUND",
//         "message":    "The requested endpoint does not exist.",
//         "request_id": "<from header or generated>",
//         "timestamp":  "<ISO 8601>"
//       }
//     }
//
// Previously, this route returned a hand-rolled envelope which was missing
// `request_id` and `timestamp`. Delegating to the shared renderer removes
// the divergence and is covered by `ApiErrorContractTest`.
// =============================================================================

Route::fallback(static function (): never {
    throw new NotFoundHttpException('The requested endpoint does not exist.');
})->name('api.fallback');