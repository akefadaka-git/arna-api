<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 7 of 7
 * =============================================================================
 *
 * File:            Routes/api.php
 * Unit:            P2.3 — correct the certificates-block cancel prose
 * Delivery type:   Amendment
 * Net lines:       +~10 docblock lines rewritten; no route changes
 *
 * Before (as File 5 left it):
 *   The certificates block's docblock stated:
 *
 *       "cancel cancels a certificate; requires certificate.cancel at
 *        the route level. The controller additionally authorizes on the
 *        certificate via Gate::authorize('view', $certificate) before
 *        the service is invoked."
 *
 *   That statement described a decision that was considered during the
 *   P2.3 design phase and rejected. File 6c retains the pre-P2.3
 *   authorization for cancel() — Gate::authorize('update', $shareholder)
 *   — on the reasoning that a write action's per-object check must not
 *   be weakened by a read-focused wiring unit. The docblock was written
 *   against the rejected option and no longer matches the code.
 *
 * After:
 *   The certificates block's cancel paragraph is rewritten to describe
 *   the actual authorization: cancel() dispatches
 *   Gate::authorize('update', $shareholder), which resolves through
 *   ShareholderPolicy::update. The paragraph notes that cancellation is
 *   an administrative act and cross-references the CertificateController
 *   class docblock's §"Why cancel() retains the `update` check".
 *
 *   The certificates block's index/show/store paragraphs are already
 *   accurate as of File 5 and are unchanged. The holdings block, the
 *   classes block, the shareholders block, the /me route, and the
 *   file-level §"Authorization layering" section are unchanged.
 *
 * =============================================================================
 * Docblock audit
 * =============================================================================
 * Every prose claim in this file was re-read against the code delivered
 * by Files 5, 6a, 6b, and 6c. Findings:
 *
 *   File-level §"Authorization layering"       — accurate, unchanged.
 *   File-level §"Route ordering"               — accurate, unchanged.
 *   File-level §"Route parameter names"        — accurate, unchanged.
 *   File-level §"Response envelope"            — accurate, unchanged.
 *   File-level §"Route name namespace"         — accurate, unchanged.
 *   /me route's inline comment                 — accurate, unchanged.
 *   classes block's inline comment             — accurate, unchanged.
 *   holdings block's inline comment            — accurate, unchanged.
 *   certificates block's inline comment        — ONE STALE PARAGRAPH,
 *                                                 corrected by this file.
 *   shareholders block's inline comment        — accurate, unchanged.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Route added/removed/renamed: none
 * Middleware changes:        none
 * Version:                   1.2.0 → 1.2.1 (PATCH — prose only)
 * =============================================================================
 */

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers\CertificateController;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers\MyShareholderController;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers\ShareClassController;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers\ShareHoldingController;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers\ShareholderController;

/*
|--------------------------------------------------------------------------
| Shareholder Domain — API Routes
|--------------------------------------------------------------------------
|
| This file defines the HTTP surface of the Shareholder domain. It is
| loaded by ShareholderServiceProvider::loadDomainRoutes() (2A.2) inside a
| group already prefixed by config('shareholder.routes.prefix') and
| wrapped in the middleware stack declared by
| config('shareholder.routes.middleware') — typically:
|
|     ['api', 'auth:sanctum', 'tenant.membership']
|
| Because the outer prefix and middleware are applied by the provider,
| the routes declared below are RELATIVE. A `Route::get('/', ...)` here
| resolves to GET /api/shareholders (assuming the default prefix).
|
| -----------------------------------------------------------------------------
| Route parameter names
| -----------------------------------------------------------------------------
| Route parameter names MUST match the parameter names of the
| corresponding controller methods, because Laravel binds route
| parameters to controller arguments by NAME. For example:
|
|     Route::get('/{shareholder}', [ShareholderController::class, 'show']);
|
| binds to:
|
|     public function show(Request $request, Shareholder $shareholder)
|
| Renaming the controller parameter without also renaming the route
| parameter would break implicit route model binding and produce a
| BindingResolutionException at runtime.
|
| -----------------------------------------------------------------------------
| Route ordering
| -----------------------------------------------------------------------------
| Static sub-paths (`/me`, `/classes`, `/holdings`, `/certificates`) are
| declared BEFORE the dynamic `/{shareholder}` route so that Laravel's
| first-match router resolves them by name rather than treating the
| literal string as a shareholder id. The dynamic route is additionally
| constrained to numeric ids via `->whereNumber('shareholder')` as a
| second line of defence — a hypothetical `/api/shareholders/foo` would
| produce a 404 from the router, not an attempt to resolve a shareholder
| with the string id "foo".
|
| The `/me` route is declared first among the static sub-paths because
| it is the only one that is not a prefix block. See the inline comment
| on the declaration below for why it takes this position.
|
| -----------------------------------------------------------------------------
| Authorization layering
| -----------------------------------------------------------------------------
| Three authorization patterns cooperate in this file:
|
|   1. Route middleware (`auth.permission:X`) enforces a single permission
|      before the controller runs. It is applied where the ability maps
|      to exactly one permission with no OR logic:
|
|        · holdings.entries.store   → share_holding.entry
|        · certificates.store       → certificate.issue
|        · certificates.cancel      → certificate.cancel
|        · shareholders.index       → shareholder.view
|        · shareholders.store       → shareholder.create
|        · shareholders.update      → shareholder.update
|
|   2. The policy layer (ShareholderPolicy, ShareHoldingPolicy,
|      CertificatePolicy, ShareClassPolicy) is invoked by the controllers
|      via Gate::authorize() for abilities that can be granted through
|      multiple paths — e.g. the view ability, which an administrator
|      holds through the administrative slug, a self-service user holds
|      through the `_view_own` slug combined with a direct link, and a
|      representative holds through a structural relationship.
|
|   3. Neither — the `/me` route, which resolves the caller's own record
|      by construction and needs no authorization boundary. See the
|      MyShareholderController class docblock for the reasoning.
|
| Endpoints where the permission is unambiguous receive both layers.
| Endpoints where the ability has an OR rely on the controller's Gate
| check alone — applying a route middleware that requires a single
| permission would lock out a caller who legitimately holds an
| alternative grant.
|
| As of P2.3, the classes block joins the holdings and certificates
| blocks in relying exclusively on the policy layer. Its prior
| `auth.permission:share_class.view` middleware was removed so that a
| caller holding only `share_class.manage` — which the policy treats as
| a superset of `share_class.view` — can reach the read routes. The
| ShareClassController calls Gate::authorize('viewAny', ShareClass::class)
| and Gate::authorize('view', $shareClass) to perform the check.
|
| -----------------------------------------------------------------------------
| Response envelope
| -----------------------------------------------------------------------------
| Every successful response follows the canonical MASES envelope:
|
|     { "success": true, "data": ... }
|
| Errors are not produced by any route here. Validation failures,
| authorization denials, and not-found conditions are all raised as
| exceptions by the controller or the framework and are transformed into
| the canonical error envelope by the renderer in bootstrap/app.php.
|
| -----------------------------------------------------------------------------
| Route name namespace
| -----------------------------------------------------------------------------
| All route names are prefixed with `api.shareholders.` so that they are
| self-describing and do not collide with any other domain's route
| names. Example full names:
|
|     api.shareholders.index
|     api.shareholders.show
|     api.shareholders.me
|     api.shareholders.classes.index
|     api.shareholders.holdings.entries.store
|     api.shareholders.certificates.cancel
|
| @package MaaleArna\MASES\Core\Domains\Shareholder\Routes
| @version 1.2.1
*/

Route::name('api.shareholders.')->group(static function (): void {

    // =========================================================================
    // My shareholder — self-service identity
    // =========================================================================
    //
    // Returns the authenticated user's OWN shareholder record, resolved
    // server-side from the caller's user id within the resolved tenant
    // context. The caller supplies no identity input; the two-outcome
    // response (`linked: true | false`) is defined by
    // MyShareholderController (P1-1a).
    //
    // Declared FIRST among the static sub-paths, before the `/classes`,
    // `/holdings`, and `/certificates` prefix blocks. The placement is
    // deliberate:
    //
    //   · The route is a single leaf, not a prefix block, so it cannot
    //     be grouped with the three sub-resources that follow.
    //
    //   · Placing it at the top of the static group keeps the file's
    //     reading order aligned with the portal's identity-first flow:
    //     resolve identity (me) → then fetch that identity's holdings
    //     and certificates.
    //
    //   · The route MUST be declared before the dynamic `/{shareholder}`
    //     route at the bottom. Although `/me` is not a numeric id and
    //     would not match `->whereNumber('shareholder')` even if it
    //     appeared later, declaring it first is the discipline the
    //     file's §"Route ordering" section documents, and it makes the
    //     precedence self-evident to a reader.
    //
    // No per-route middleware. The route inherits `api`, `auth:sanctum`,
    // and `tenant.membership` from the outer group applied by
    // ShareholderServiceProvider::loadDomainRoutes(). No permission
    // middleware is applied — see the class docblock of
    // MyShareholderController for why the identity endpoint is not
    // permission-gated.
    //
    // Routes:
    //     GET  /api/shareholders/me
    // =========================================================================

    Route::get('/me', [MyShareholderController::class, 'show'])
        ->name('me');

    // =========================================================================
    // Share Classes — static sub-resource
    // =========================================================================
    //
    // Declared before the dynamic `/{shareholder}` routes.
    //
    // Share classes are global reference data (D3). The two endpoints
    // both delegate authorization to ShareClassPolicy via the
    // controller's Gate::authorize() call:
    //
    //     ShareClassController::index → Gate::authorize('viewAny', ShareClass::class)
    //     ShareClassController::show  → Gate::authorize('view', $shareClass)
    //
    // ShareClassPolicy grants through a disjunction of `share_class.view`
    // OR `share_class.manage` — treating the manage slug as a superset of
    // the read slug. A group-level `auth.permission:share_class.view`
    // middleware previously gated these routes, which prevented a
    // manage-only caller from reaching the policy. That middleware was
    // removed in P2.3 to let the policy's disjunction take effect. See
    // the ShareClassPolicy class docblock §"The manage-as-superset rule".
    //
    // Routes:
    //     GET  /api/shareholders/classes
    //     GET  /api/shareholders/classes/{shareClass}
    // =========================================================================

    Route::prefix('classes')
        ->name('classes.')
        ->group(static function (): void {
            Route::get('/', [ShareClassController::class, 'index'])
                ->name('index');

            Route::get('/{shareClass}', [ShareClassController::class, 'show'])
                ->whereNumber('shareClass')
                ->name('show');
        });

    // =========================================================================
    // Share Holdings — static sub-resource
    // =========================================================================
    //
    // `index` requires the caller to identify the shareholder whose
    // holdings are being listed via a `?shareholder_id=X` query parameter.
    // The controller resolves it against the tenant and authorizes the
    // caller against the resolved shareholder via
    // ShareHoldingPolicy::viewForShareholder (P2.3).
    //
    // `store` records a balance-changing entry. Requires the
    // `share_holding.entry` permission at the route level; the target
    // shareholder is additionally authorized via
    // ShareHoldingPolicy::viewForShareholder in the controller before
    // the entry is recorded.
    //
    // `index` and `show` do NOT apply a permission middleware — their
    // authorization is delegated to the policy layer, so a caller
    // authorized through any of the three paths (holding-domain admin
    // slug, holding-domain self-service slug plus a direct link, or the
    // delegated shareholder policy) can access the holdings.
    //
    // Routes:
    //     GET  /api/shareholders/holdings
    //     GET  /api/shareholders/holdings/{shareHolding}
    //     POST /api/shareholders/holdings/entries
    // =========================================================================

    Route::prefix('holdings')
        ->name('holdings.')
        ->group(static function (): void {
            Route::get('/', [ShareHoldingController::class, 'index'])
                ->name('index');

            Route::get('/{shareHolding}', [ShareHoldingController::class, 'show'])
                ->whereNumber('shareHolding')
                ->name('show');

            Route::post('/entries', [ShareHoldingController::class, 'store'])
                ->middleware('auth.permission:share_holding.entry')
                ->name('entries.store');
        });

    // =========================================================================
    // Share Certificates — static sub-resource
    // =========================================================================
    //
    // `index`, `show`, and `store` delegate their per-object or
    // per-collection authorization to CertificatePolicy (P2.3) via the
    // controller's Gate::authorize() call:
    //
    //     CertificateController::index → Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])
    //     CertificateController::show  → Gate::authorize('view', $certificate)
    //     CertificateController::store → Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])
    //
    // CertificatePolicy grants reads through a three-path OR:
    // `certificate.view`, `certificate.view_own` plus a direct link, or
    // the delegated ShareholderPolicy::view. See the CertificatePolicy
    // class docblock for the full reasoning.
    //
    // `cancel` does NOT delegate to CertificatePolicy. Cancellation is
    // a WRITE action, and its per-object authorization retains the
    // pre-P2.3 check:
    //
    //     CertificateController::cancel → Gate::authorize('update', $shareholder)
    //
    // The caller must hold `shareholder.update` in the resolved tenant,
    // and the route-level `auth.permission:certificate.cancel`
    // middleware enforces the action-specific grant independently.
    // Switching cancel to the read-oriented `view` ability was
    // considered during the P2.3 design phase and rejected: it would
    // weaken the check by admitting self-service and representative
    // callers who hold no administrative grant. See the
    // CertificateController class docblock §"Why cancel() retains the
    // `update` check".
    //
    // There is deliberately no `replace` route — see F-2A24-1.
    //
    // Routes:
    //     GET  /api/shareholders/certificates
    //     GET  /api/shareholders/certificates/{certificate}
    //     POST /api/shareholders/certificates
    //     POST /api/shareholders/certificates/{certificate}/cancel
    // =========================================================================

    Route::prefix('certificates')
        ->name('certificates.')
        ->group(static function (): void {
            Route::get('/', [CertificateController::class, 'index'])
                ->name('index');

            Route::get('/{certificate}', [CertificateController::class, 'show'])
                ->whereNumber('certificate')
                ->name('show');

            Route::post('/', [CertificateController::class, 'store'])
                ->middleware('auth.permission:certificate.issue')
                ->name('store');

            Route::post('/{certificate}/cancel', [CertificateController::class, 'cancel'])
                ->middleware('auth.permission:certificate.cancel')
                ->whereNumber('certificate')
                ->name('cancel');
        });

    // =========================================================================
    // Shareholders — the domain's primary resource
    // =========================================================================
    //
    // Declared last so that the static sub-resources above take
    // precedence. The dynamic `/{shareholder}` routes are constrained to
    // numeric ids as a second line of defence.
    //
    // `index` lists shareholders. Requires `shareholder.view` at the
    // route level (the policy's `viewAny` also checks this — the
    // middleware is redundant defence in depth).
    //
    // `store` creates a shareholder. Requires `shareholder.create` at the
    // route level.
    //
    // `show` and `update` do NOT apply a permission middleware for show
    // (its ability has an OR across admin permission, self-service, and
    // representative access). `update` DOES apply the middleware because
    // its ability resolves to exactly one permission.
    //
    // Routes:
    //     GET   /api/shareholders
    //     POST  /api/shareholders
    //     GET   /api/shareholders/{shareholder}
    //     PATCH /api/shareholders/{shareholder}
    //     PUT   /api/shareholders/{shareholder}
    // =========================================================================

    Route::get('/', [ShareholderController::class, 'index'])
        ->middleware('auth.permission:shareholder.view')
        ->name('index');

    Route::post('/', [ShareholderController::class, 'store'])
        ->middleware('auth.permission:shareholder.create')
        ->name('store');

    Route::get('/{shareholder}', [ShareholderController::class, 'show'])
        ->whereNumber('shareholder')
        ->name('show');

    Route::match(['patch', 'put'], '/{shareholder}', [ShareholderController::class, 'update'])
        ->middleware('auth.permission:shareholder.update')
        ->whereNumber('shareholder')
        ->name('update');
});