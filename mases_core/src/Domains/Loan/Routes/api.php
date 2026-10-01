<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L1
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Routes/api.php  (AMEND)
 * Unit:            CR-L1 — Portal self-service loans endpoint
 * Delivery type:   Amendment — one import added, one route declared,
 *                  docblock updated to reflect the seventeen-route
 *                  surface, version bump.
 * Net lines:       +1 import; +8 lines route declaration; ~50 lines
 *                  docblock.
 *
 * Before:
 *   The file declared the frozen sixteen-endpoint HTTP surface. Every
 *   endpoint addressed a specific loan (by the route-bound {loan}) or
 *   the tenant's collection (GET /loans). There was no self-service
 *   route; a caller had no way to reach their own loans except through
 *   the aggregate index, which carries no identity contract.
 *
 * After:
 *   The file declares seventeen routes. The new seventeenth is:
 *
 *       GET  /loans/me   →  MyLoanController::index()   name: api.loans.me
 *
 *   It is declared as a STATIC sibling of /products, BEFORE the
 *   dynamic /{loan} route, per the file's own §"The route-ORDERING
 *   rule". The ->whereNumber('loan') constraint on the dynamic route
 *   provides the second line of defence, as it does for /products.
 *
 *   The route carries NO route middleware. The reasoning mirrors the
 *   schedule and statement routes (which also carry no middleware in
 *   this file): the endpoint resolves the caller's own loans through
 *   a server-side chain that accepts no caller input, and cannot be
 *   steered at another borrower's row. MyLoanController's class
 *   docblock records the full reasoning.
 *
 *   Route name: `api.loans.me`. Follows the file's existing
 *   `api.loans.<action>` naming pattern; matches `api.shareholders.me`
 *   and `api.wallets.me` from the sibling domains.
 *
 * Dependency note:
 *   · The route references MyLoanController, delivered as CR-L1 file
 *     2. Until that class is uploaded and the Composer autoloader is
 *     refreshed (via the Plesk Composer panel), the route will resolve
 *     to a class-not-found error at the first request — but
 *     `php artisan route:list` will still show the route name and path,
 *     because the route definition itself does not require the
 *     controller class to be loaded.
 *
 *   · The route's URL depends on config/loan.php's `routes.prefix`,
 *     corrected by CR-L1 file 1 from `loans` to `api/loans`. Until
 *     that config amendment is deployed AND the config cache is
 *     cleared, the route will resolve at `/loans/me` instead of
 *     `/api/loans/me`. Both files ship together as CR-L1; the
 *     sequence in the change-summary reflects this dependency.
 *
 *   · No change to the file's middleware or prefix (those are the
 *     provider's concern; the file applies only the route-NAME prefix).
 *
 *   · No change to any existing route.
 *
 * =============================================================================
 */

/*
 * -----------------------------------------------------------------------------
 * MASES LOAN DOMAIN — HTTP ROUTES
 * -----------------------------------------------------------------------------
 *
 * The frozen seventeen-endpoint HTTP surface for the Loan domain. This
 * file is loaded by `LoanServiceProvider::loadDomainRoutes()` (A.85),
 * which wraps it in a route group built from the domain's config (A.1):
 *
 *     Route::middleware(config('loan.routes.middleware'))
 *          ->prefix(config('loan.routes.prefix'))
 *          ->group(__DIR__ . '/../Routes/api.php');
 *
 * The FILE therefore does NOT apply the middleware stack and does NOT
 * apply the URL prefix; both are the provider's concern. The file DOES
 * apply the route-NAME prefix `api.loans.`, which is a stable routing
 * identifier independent of the URL prefix.
 *
 * =============================================================================
 * Route surface — sixteen frozen endpoints + CR-L1's self-service route
 * =============================================================================
 * The sixteen-endpoint surface frozen in Stage 6 §4.16 is preserved
 * byte-for-byte. CR-L1 adds a seventeenth route — the self-service
 * `/me` — for the portal. Every route in this file carries a name
 * beginning with `api.loans.`:
 *
 *     api.loans.index                    GET    /loans
 *     api.loans.store                    POST   /loans
 *     api.loans.products.index           GET    /loans/products
 *     api.loans.me                       GET    /loans/me          ← CR-L1
 *     api.loans.show                     GET    /loans/{loan}
 *     api.loans.approve                  POST   /loans/{loan}/approve
 *     api.loans.reject                   POST   /loans/{loan}/reject
 *     api.loans.disburse                 POST   /loans/{loan}/disburse
 *     api.loans.repay                    POST   /loans/{loan}/repay
 *     api.loans.schedule.show            GET    /loans/{loan}/schedule
 *     api.loans.statement.show           GET    /loans/{loan}/statement
 *     api.loans.reconciliation.show      GET    /loans/{loan}/reconciliation
 *     api.loans.restructure              POST   /loans/{loan}/restructure
 *     api.loans.writeoff                 POST   /loans/{loan}/writeoff
 *     api.loans.collateral.index         GET    /loans/{loan}/collateral
 *     api.loans.collateral.store         POST   /loans/{loan}/collateral
 *     api.loans.collateral.release       POST   /loans/{loan}/collateral/{collateral}/release
 *
 * =============================================================================
 * The route-ORDERING rule — static paths before /loans/{loan}
 * =============================================================================
 * The static paths `/loans/products` and `/loans/me` MUST be declared
 * BEFORE the dynamic `GET /loans/{loan}`. Laravel matches routes in
 * the order they are declared; if the dynamic route were declared
 * first, a request for `/loans/products` or `/loans/me` would be
 * captured as `{loan} = 'products'` or `{loan} = 'me'` and routed to
 * `LoanController::show()`, which would then reject the value with a
 * 404 or a 422.
 *
 * The `->whereNumber('loan')` constraint on the dynamic route provides
 * a SECOND line of defence: even if the ordering were violated,
 * Laravel would reject the `'products'` / `'me'` values for lacking
 * the numeric shape the constraint requires. Both protections are in
 * place, deliberately — the ordering is the PRIMARY fix, and the
 * constraint is the DEFENSIVE one.
 *
 * The same ordering principle applies to `{collateral}` — but the
 * collateral parameter only appears under `{loan}`, so no sibling
 * static path competes with it.
 *
 * =============================================================================
 * The `->whereNumber(...)` constraints
 * =============================================================================
 * Both dynamic path segments carry a numeric constraint:
 *
 *     ->whereNumber('loan')
 *     ->whereNumber('collateral')
 *
 * A non-numeric value for either segment produces a 404 (Laravel's
 * default behavior when no route matches). This is the correct outcome:
 * a request for `/loans/abc` or `/loans/1/collateral/xyz/release`
 * addresses a resource that cannot exist.
 *
 * The constraints are declared per-route rather than via a global pattern
 * (e.g., `Route::pattern('loan', '\d+')`). The per-route form is
 * explicit at each declaration and greppable; the global form would
 * apply silently to every route in the group and would couple the
 * pattern to the group's scope. The per-route form is the platform-wide
 * convention.
 *
 * =============================================================================
 * Route verbs and their controllers
 * =============================================================================
 * Each route delegates to a single controller action. The controller
 * actions are thin delegators (see the controllers A.77–A.83 and
 * CR-L1's MyLoanController); the routes file does not concern itself
 * with their internals.
 *
 * The verbs follow the semantic intent:
 *
 *   GET     for reads (index, show, sub-resource views, self-service).
 *   POST    for every state-mutating operation — including the
 *           lifecycle transitions (approve, reject, disburse,
 *           restructure, writeoff), the monetary operations (repay),
 *           and the collateral operations (store, release).
 *
 * No PUT, PATCH, or DELETE verb is used. The Loan domain's mutations
 * are not idempotent resource replacements; they are lifecycle actions
 * and ledger operations. POST is the correct verb for each. This mirrors
 * the convention established by Phase 2A's shareholder certificate
 * endpoints and by Phase 2B's wallet endpoints.
 *
 * =============================================================================
 * Endpoint-to-controller map
 * =============================================================================
 *   LoanController                 (A.77) — index, store, show, approve,
 *                                            reject, disburse,
 *                                            restructure, writeoff
 *   MyLoanController               (CR-L1) — index (self-service)
 *   LoanProductController          (A.78) — index
 *   LoanCollateralController       (A.79) — index, store, release
 *   LoanRepaymentController        (A.80) — store
 *   LoanScheduleController         (A.81) — show
 *   LoanStatementController        (A.82) — show
 *   LoanReconciliationController   (A.83) — show
 *
 * =============================================================================
 * The middleware stack (applied by the provider, NOT this file)
 * =============================================================================
 * The three middleware the domain requires — `api`, `auth:sanctum`, and
 * `tenant.membership` — are applied by the provider (A.85) from the
 * config's `routes.middleware` array (A.1). See that config's docblock
 * for the guarantees each middleware provides.
 *
 * The controller actions assume the middleware has run:
 *
 *   - `auth:sanctum` guarantees an authenticated `$request->user()`.
 *   - `tenant.membership` populates `mases_tenant_id()`.
 *
 * A route registered outside the domain's middleware group would violate
 * these assumptions; the provider is the single enforcement point. The
 * `me` route added by CR-L1 inherits the same stack — its no-route-
 * middleware posture concerns the `auth.permission:*` middleware only,
 * not the config-declared group stack.
 *
 * =============================================================================
 * What this file deliberately does NOT do
 * =============================================================================
 *   - It does NOT apply the middleware stack. See §"The middleware
 *     stack".
 *
 *   - It does NOT apply the URL prefix. The provider applies the
 *     `api/loans` prefix from the config (as corrected by CR-L1 file 1).
 *
 *   - It does NOT declare a `fallback` route. Unmatched requests fall
 *     through to the platform's default 404 handling.
 *
 *   - It does NOT declare a route for a `withdraw` action. Phase 2C
 *     does not implement withdrawal (the frozen C4 state vocabulary has
 *     no `withdrawn` state; the frozen HTTP surface has no withdraw
 *     endpoint). A future CR that introduces withdrawal extends this
 *     file at that time.
 *
 *   - It does NOT declare a `close` route. Phase 2C does not implement
 *     closure; the `LoanClosed` event (A.49) is a reserved artefact with
 *     no dispatcher. A future CR that introduces closure extends this
 *     file at that time.
 *
 *   - It does NOT declare product mutation routes (create, update,
 *     delete, activate, deactivate). Phase 2C's product catalogue is a
 *     read-only surface.
 *
 *   - It does NOT declare collateral `show`, `update`, or `delete`
 *     routes. Phase 2C exposes only index, store, and release for
 *     collaterals.
 *
 *   - It does NOT use route caching annotations or closures. Every
 *     route delegates to a controller action, so the file is
 *     `route:cache`-compatible.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C1–C5
 *   Phase 2C A.1                                 — `Config/loan.php`
 *                                                  (the middleware and
 *                                                  prefix the provider
 *                                                  applies)
 *   Phase 2C A.77–A.83                           — the seven controllers
 *                                                  this file routes to
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *                                                  (the loader)
 *   Phase 2A `Routes/api.php`                    — the shape precedent
 *   Phase 2B `Routes/api.php`                    — the direct sibling
 *   CR-W1                                        — the `/me` route
 *                                                  precedent
 *   CR-L1                                        — This amendment
 *   Step 5 (manifest) A.84                       — This file
 */

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanCollateralController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanProductController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanReconciliationController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanRepaymentController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanScheduleController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanStatementController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\MyLoanController;

Route::name('api.loans.')->group(function (): void {

    // =========================================================================
    // Product catalogue — MUST be declared before the dynamic `{loan}` route.
    //
    // GET /loans/products
    //
    // See the class docblock §"The route-ORDERING rule" for the rationale
    // on the ordering and the constraint.
    // =========================================================================
    Route::get('products', [LoanProductController::class, 'index'])
        ->name('products.index');

    // =========================================================================
    // Self-service — the authenticated caller's own loans.  (CR-L1)
    //
    // GET /loans/me
    //
    // Declared BEFORE the dynamic `/{loan}` route, for the same reason
    // `/products` is: the static path must be matched first, or the
    // router would capture `me` as `{loan}` and route to
    // LoanController::show(). The ->whereNumber('loan') constraint on
    // the dynamic route provides the second line of defence. See the
    // class docblock §"The route-ORDERING rule".
    //
    // NO route middleware. The endpoint resolves the caller's own loans
    // through a server-side chain that accepts no caller input and
    // cannot be steered at another borrower's row. See
    // MyLoanController's class docblock §"Authorization — why there is
    // no Gate::authorize" for the full reasoning. This mirrors the
    // schedule and statement routes below, which likewise carry no
    // route middleware.
    // =========================================================================
    Route::get('me', [MyLoanController::class, 'index'])
        ->name('me');

    // =========================================================================
    // Loan collection — index and store.
    //
    // GET  /loans
    // POST /loans
    // =========================================================================
    Route::get('/', [LoanController::class, 'index'])
        ->name('index');

    Route::post('/', [LoanController::class, 'store'])
        ->name('store');

    // =========================================================================
    // Loan show — the dynamic single-resource read.
    //
    // GET /loans/{loan}
    //
    // Declared AFTER `/loans/products` and `/loans/me` (see the class
    // docblock §"The route-ORDERING rule").
    // =========================================================================
    Route::get('{loan}', [LoanController::class, 'show'])
        ->whereNumber('loan')
        ->name('show');

    // =========================================================================
    // Lifecycle transitions — every one is a POST.
    //
    // POST /loans/{loan}/approve
    // POST /loans/{loan}/reject
    // POST /loans/{loan}/disburse
    // POST /loans/{loan}/restructure
    // POST /loans/{loan}/writeoff
    // =========================================================================
    Route::post('{loan}/approve', [LoanController::class, 'approve'])
        ->whereNumber('loan')
        ->name('approve');

    Route::post('{loan}/reject', [LoanController::class, 'reject'])
        ->whereNumber('loan')
        ->name('reject');

    Route::post('{loan}/disburse', [LoanController::class, 'disburse'])
        ->whereNumber('loan')
        ->name('disburse');

    Route::post('{loan}/restructure', [LoanController::class, 'restructure'])
        ->whereNumber('loan')
        ->name('restructure');

    Route::post('{loan}/writeoff', [LoanController::class, 'writeoff'])
        ->whereNumber('loan')
        ->name('writeoff');

    // =========================================================================
    // Monetary operation — a repayment is a POST.
    //
    // POST /loans/{loan}/repay
    // =========================================================================
    Route::post('{loan}/repay', [LoanRepaymentController::class, 'store'])
        ->whereNumber('loan')
        ->name('repay');

    // =========================================================================
    // Sub-resource reads — schedule, statement, reconciliation.
    //
    // GET /loans/{loan}/schedule
    // GET /loans/{loan}/statement
    // GET /loans/{loan}/reconciliation
    // =========================================================================
    Route::get('{loan}/schedule', [LoanScheduleController::class, 'show'])
        ->whereNumber('loan')
        ->name('schedule.show');

    Route::get('{loan}/statement', [LoanStatementController::class, 'show'])
        ->whereNumber('loan')
        ->name('statement.show');

    Route::get('{loan}/reconciliation', [LoanReconciliationController::class, 'show'])
        ->whereNumber('loan')
        ->name('reconciliation.show');

    // =========================================================================
    // Collateral — index, store, release.
    //
    // GET  /loans/{loan}/collateral
    // POST /loans/{loan}/collateral
    // POST /loans/{loan}/collateral/{collateral}/release
    // =========================================================================
    Route::get('{loan}/collateral', [LoanCollateralController::class, 'index'])
        ->whereNumber('loan')
        ->name('collateral.index');

    Route::post('{loan}/collateral', [LoanCollateralController::class, 'store'])
        ->whereNumber('loan')
        ->name('collateral.store');

    Route::post(
        '{loan}/collateral/{collateral}/release',
        [LoanCollateralController::class, 'release'],
    )
        ->whereNumber('loan')
        ->whereNumber('collateral')
        ->name('collateral.release');
});