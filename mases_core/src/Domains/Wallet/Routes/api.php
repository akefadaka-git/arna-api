<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-W1
 * =============================================================================
 *
 * File:            Routes/api.php  (AMEND)
 * Unit:            CR-W1 — Portal self-service wallet endpoint
 * Delivery type:   Amendment — one import added, one route declared, class
 *                  header docblock revised to reflect the ten-route surface.
 * Net lines:       +1 import, +1 route declaration (8 source lines), docblock
 *                  revision.
 *
 * Before:
 *   The file declared exactly nine routes, all addressing a wallet by id
 *   or listing the tenant's wallets. There was no self-service endpoint
 *   answering "what is my wallet?" — the caller would have had to know
 *   a wallet id they have no way to obtain. The docblock stated the
 *   nine-route surface was frozen in Step 7 v1.1.0 §C.1 and that adding,
 *   removing, or renaming a route is a Change Request. CR-W1 is that
 *   Change Request.
 *
 * After:
 *   The file declares ten routes. The tenth is:
 *
 *       GET  /wallets/me   →  MyWalletController::show()   name: api.wallets.me
 *
 *   The route carries no middleware — the reasoning mirrors route #4
 *   (WalletController::show()) in the source: the `view` ability is
 *   granted via `wallet.view` OR (`wallet.view_own` + ownership), and a
 *   route-level `wallet.view` middleware would lock out a self-service
 *   caller holding only the own-only variant. The controller resolves
 *   the caller's wallet server-side; the route is the strongest case of
 *   the OR pattern because the caller typically holds only
 *   `wallet.view_own` and is looking at their own record by definition.
 *
 *   In the source, the new route is declared BEFORE the dynamic
 *   `/{wallet}` route so that the router's first-match resolution treats
 *   `/me` as a static segment. The numeric constraint on `{wallet}`
 *   would already make the ordering technically safe, but the file's
 *   own convention (docblock §"Route ordering") prefers static sub-paths
 *   to precede their dynamic parent for readability.
 *
 *   The route's numbered label in the docblock is #10, reflecting its
 *   addition order relative to the frozen Step 7 v1.1.0 §C.1 surface.
 *   Its declaration position in the source is between #2 (`store`) and
 *   #4 (`show`) — the position, not the label, is what matters to the
 *   router.
 *
 * Dependency note:
 *   · The route references MyWalletController, delivered as CR-W1 file 1.
 *     Until that class is uploaded and the Composer autoloader is
 *     refreshed, the route will resolve to a class-not-found error at
 *     the first request — but `php artisan route:list` will still show
 *     the route name and path, because the route definition itself does
 *     not require the controller class to be loaded.
 *   · No config change. The domain's provider (2B.2) mounts this file
 *     under `wallet.routes.prefix` (default `'wallets'`) inside the
 *     `wallet.routes.middleware` stack. The new route inherits the same
 *     middleware as its siblings: `['api', 'auth:sanctum',
 *     'tenant.membership']` plus the application-level `api/` prefix.
 *
 * =============================================================================
 */

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\AllocationController;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\MyWalletController;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\ObligationController;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\PaymentController;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\StatementController;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\WalletController;

/*
|--------------------------------------------------------------------------
| Wallet Domain — API Routes
|--------------------------------------------------------------------------
|
| This file defines the HTTP surface of the Wallet domain. It is loaded by
| WalletServiceProvider::loadDomainRoutes() (2B.2) inside a group already
| prefixed by config('wallet.routes.prefix') and wrapped in the middleware
| stack declared by config('wallet.routes.middleware') — typically:
|
|     ['api', 'auth:sanctum', 'tenant.membership']
|
| Because the outer prefix and middleware are applied by the provider, the
| routes declared below are RELATIVE. A `Route::get('/', ...)` here
| resolves to GET /wallets (assuming the default prefix of `wallets`).
|
| -----------------------------------------------------------------------------
| Ten routes — the 2B surface plus CR-W1
| -----------------------------------------------------------------------------
| This file declared exactly the nine routes frozen in Step 7 v1.1.0 §C.1.
| Adding, removing, or renaming a route is a Change Request; the route name
| is part of the domain's public API contract and is referenced by tests
| and by any future client.
|
| CR-W1 is such a Change Request. It adds a tenth route — the self-service
| endpoint that resolves the authenticated caller's own wallet:
|
|     #1  GET   /wallets                                            index
|     #2  POST  /wallets                                            store
|     #3  GET   /wallets/{wallet}                                   show
|     #4  POST  /wallets/{wallet}/adjust                           adjust
|     #5  POST  /wallets/{wallet}/obligations                      obligations.store
|     #6  POST  /wallets/{wallet}/payments                         payments.store
|     #7  POST  /wallets/{wallet}/allocations                      allocations.store
|     #8  POST  /wallets/{wallet}/allocations/{allocation}/reverse allocations.reverse
|     #9  GET   /wallets/{wallet}/statement                        statement.show
|     #10 GET   /wallets/me                                         me  (CR-W1)
|
| Route #10 is numbered last because the #1–#9 labels are referenced by
| Step 7 v1.1.0 §C.1 and by test files; renumbering would invalidate those
| references. Its DECLARATION position in the source is between route #2
| (`store`) and route #3 (`show`) — before the dynamic `/{wallet}` parent
| — so the router's first-match resolution treats `/me` as a static
| segment. The numeric constraint on `{wallet}` would make the ordering
| technically safe either way; the declaration order follows the file's
| own §"Route ordering" convention.
|
| -----------------------------------------------------------------------------
| Route parameter names
| -----------------------------------------------------------------------------
| Route parameter names MUST match the parameter names of the corresponding
| controller methods, because Laravel binds route parameters to controller
| arguments by NAME.
|
| For the Wallet domain, the two dynamic parameter names are:
|
|     {wallet}      — bound to the `$wallet` parameter of:
|                       WalletController::show()
|                       WalletController::adjust()
|                       ObligationController::store()
|                       PaymentController::store()
|                       AllocationController::store()
|                       AllocationController::reverse()
|                       StatementController::show()
|
|     {allocation}  — bound to the `$allocation` parameter of:
|                       AllocationController::reverse()
|
| Route #10 (`/me`) declares no parameters. Its controller method
| (MyWalletController::show()) receives only the Request — the identity
| resolution is entirely server-side and takes no route input.
|
| All controllers receive their route parameters as STRING and resolve
| them through tenant-scoped (or wallet-scoped) repository lookups, NOT
| through implicit route-model binding. See the controllers' class
| docblocks §"Tenant scoping" (2B.26–2B.30) for the rationale.
|
| -----------------------------------------------------------------------------
| Route ordering
| -----------------------------------------------------------------------------
| Static sub-paths are declared with their dynamic parent (`{wallet}`)
| already constrained to numeric ids via `->whereNumber('wallet')`. This
| is the primary line of defence: no non-numeric segment can be mistaken
| for a wallet id.
|
| The `index` and `store` routes (`/`) are declared BEFORE the `{wallet}`
| route for readability; the numeric constraint means ordering is not
| strictly required for correctness, but the declaration order matches the
| resource's natural reading order.
|
| Route #10 (`/me`) follows the same convention: it is a static top-level
| segment and is declared before the dynamic `/{wallet}` route. The
| numeric constraint on `{wallet}` would route `/me` correctly even if
| declared after, but the declaration-before convention keeps the file
| readable and expresses the intent.
|
| The `{allocation}` parameter on route #8 is likewise constrained to
| numeric ids via `->whereNumber('allocation')`.
|
| -----------------------------------------------------------------------------
| Authorization layering
| -----------------------------------------------------------------------------
| Two authorization layers cooperate (Step 7 v1.1.0 §C.1, §C.1.1):
|
|   1. Route middleware (`auth.permission:X`) enforces a single permission
|      before the controller is instantiated. It is applied only where the
|      ability maps to exactly one permission with no OR logic.
|
|   2. The policy layer (WalletPolicy, 2B.37; and the delegating
|      ObligationPolicy and PaymentPolicy, 2B.38–2B.39) is invoked by the
|      controller via Gate::authorize() for abilities that can be granted
|      through multiple paths.
|
| Two Step 7 routes carry NO route middleware, because the ability they
| need has an OR across a broad permission and an own-only permission:
|
|     Route #3  GET  /wallets/{wallet}
|         → Gate::authorize('view', $wallet)
|         → granted via wallet.view OR (wallet.view_own + ownership)
|
|     Route #9  GET  /wallets/{wallet}/statement
|         → Gate::authorize('viewStatement', $wallet)
|         → granted via wallet.statement.view
|                    OR (wallet.statement.view_own + ownership)
|
| Applying a route-level middleware that requires the broad permission
| would lock out a manager who holds only the own-only variant, defeating
| the purpose of the second permission. See Step 7 v1.1.0 §C.1.1 for the
| full reasoning, particularly for the statement route (the S7-A
| amendment).
|
| CR-W1's route #10 (`/me`) carries NO route middleware for a related but
| distinct reason. The endpoint resolves the caller's own wallet through
| the structural chain
|
|     mases_tenant_id() → $request->user()->id
|                       → ShareholderRepository::findByUser()
|                       → WalletRepository::findForShareholder()
|
| None of these steps accepts caller-supplied input; the endpoint cannot
| be steered at another row. The WalletPolicy's `view` ability governs
| access to OTHER wallets; applying it here would invert the dependency —
| a `wallet.view_own` permission would gate the identity-resolution step
| that determines whether the permission is meaningful. MyWalletController
| documents this reasoning at length in its own class docblock, mirroring
| the Shareholder domain's identical decision for /api/shareholders/me
| (P1-1a). The policy layer is not touched by CR-W1.
|
| The remaining seven Step 7 routes carry route middleware as shown in
| the per-route declarations below.
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
| All route names are prefixed with `api.wallets.` so that they are
| self-describing and do not collide with any other domain's route names.
| Example full names:
|
|     api.wallets.index
|     api.wallets.store
|     api.wallets.show
|     api.wallets.adjust
|     api.wallets.obligations.store
|     api.wallets.payments.store
|     api.wallets.allocations.store
|     api.wallets.allocations.reverse
|     api.wallets.statement.show
|     api.wallets.me                     (CR-W1)
|
| @package MaaleArna\MASES\Core\Domains\Wallet\Routes
| @version 1.1.0
*/

Route::name('api.wallets.')->group(static function (): void {

    // =========================================================================
    // Route #1 — Index wallets.
    //
    //     GET /wallets
    //
    // Lists the tenant's wallets. Requires `wallet.view` at the route
    // level; the controller additionally calls
    // `Gate::authorize('viewAny', Wallet::class)` (2B.26 §"index"),
    // closing F-2A47-1.
    // =========================================================================
    Route::get('/', [WalletController::class, 'index'])
        ->middleware('auth.permission:wallet.view')
        ->name('index');

    // =========================================================================
    // Route #2 — Store (open) a wallet.
    //
    //     POST /wallets
    //
    // Opens a new wallet for a shareholder. Requires `wallet.open` at the
    // route level. No additional policy gate: `open` creates a wallet and
    // has no target to gate against. The controller resolves the
    // shareholder through the tenant-scoped ShareholderRepository before
    // invoking the service (2B.26 §"store").
    // =========================================================================
    Route::post('/', [WalletController::class, 'store'])
        ->middleware('auth.permission:wallet.open')
        ->name('store');

    // =========================================================================
    // Route #10 — Show the authenticated caller's own wallet.  (CR-W1)
    //
    //     GET /wallets/me
    //
    // Self-service identity resolution. NO route middleware — see the
    // file header §"Authorization layering" for the reasoning, which
    // mirrors route #3 (show) and the Shareholder domain's
    // /api/shareholders/me precedent.
    //
    // Declared HERE, before the dynamic `/{wallet}` route, so that the
    // router's first-match resolution treats `/me` as a static segment.
    // The numeric constraint on `{wallet}` would make the ordering
    // technically safe regardless, but declaring static sub-paths before
    // their dynamic parent is the file's stated convention (see file
    // header §"Route ordering").
    //
    // The controller resolves the caller's wallet server-side, through
    // ShareholderRepository::findByUser() and
    // WalletRepository::findForShareholder(). No caller-supplied input
    // steers the query.
    // =========================================================================
    Route::get('/me', [MyWalletController::class, 'show'])
        ->name('me');

    // =========================================================================
    // Route #3 — Show a single wallet.
    //
    //     GET /wallets/{wallet}
    //
    // NO route middleware. The `view` ability has an OR across
    // `wallet.view` and `wallet.view_own`; the controller calls
    // `Gate::authorize('view', $wallet)` and the policy (2B.37) grants
    // via either path. See the file header §"Authorization layering" for
    // the reasoning.
    // =========================================================================
    Route::get('/{wallet}', [WalletController::class, 'show'])
        ->whereNumber('wallet')
        ->name('show');

    // =========================================================================
    // Route #4 — Adjust a wallet's balance.
    //
    //     POST /wallets/{wallet}/adjust
    //
    // Operator-initiated balance adjustment. Requires `wallet.adjust`
    // (privileged — held only by `super_admin` in the frozen RBAC matrix,
    // Step 7 v1.1.0 §A.2.1). The controller additionally calls
    // `Gate::authorize('view', $wallet)` as a second layer (2B.26
    // §"adjust").
    //
    // Declared before routes #5–#8 for readability; the sub-paths are
    // all distinct and none collides with the others under the numeric
    // constraint.
    // =========================================================================
    Route::post('/{wallet}/adjust', [WalletController::class, 'adjust'])
        ->middleware('auth.permission:wallet.adjust')
        ->whereNumber('wallet')
        ->name('adjust');

    // =========================================================================
    // Route #5 — Record an obligation.
    //
    //     POST /wallets/{wallet}/obligations
    //
    // Requires `wallet.obligation.create` at the route level. The
    // controller calls `Gate::authorize('view', $wallet)` as a second
    // layer (2B.27 §"store"). The obligation does not exist at request
    // time; the gate is on the target wallet.
    // =========================================================================
    Route::post('/{wallet}/obligations', [ObligationController::class, 'store'])
        ->middleware('auth.permission:wallet.obligation.create')
        ->whereNumber('wallet')
        ->name('obligations.store');

    // =========================================================================
    // Route #6 — Record a payment.
    //
    //     POST /wallets/{wallet}/payments
    //
    // Requires `wallet.payment.record` at the route level. The
    // controller calls `Gate::authorize('view', $wallet)` as a second
    // layer (2B.28 §"store").
    // =========================================================================
    Route::post('/{wallet}/payments', [PaymentController::class, 'store'])
        ->middleware('auth.permission:wallet.payment.record')
        ->whereNumber('wallet')
        ->name('payments.store');

    // =========================================================================
    // Route #7 — Record an allocation.
    //
    //     POST /wallets/{wallet}/allocations
    //
    // Requires `wallet.allocation.create` at the route level. The
    // controller calls `Gate::authorize('view', $wallet)` as a second
    // layer (2B.29 §"store"). The `payment_id` and `obligation_id` in the
    // request body are resolved by the service (2B.18) through their
    // wallet-scoped repositories — this is the endpoint where the
    // Step 7 v1.1.0 §C.6 cross-tenant protection is most load-bearing
    // (test methods T7, T8, T9).
    // =========================================================================
    Route::post('/{wallet}/allocations', [AllocationController::class, 'store'])
        ->middleware('auth.permission:wallet.allocation.create')
        ->whereNumber('wallet')
        ->name('allocations.store');

    // =========================================================================
    // Route #8 — Reverse an allocation.
    //
    //     POST /wallets/{wallet}/allocations/{allocation}/reverse
    //
    // Requires `wallet.allocation.reverse` — the privileged counterpart
    // of `wallet.allocation.create`, held only by `super_admin` and
    // `platform_admin` in the frozen RBAC matrix (Step 7 v1.1.0 §A.2.1).
    // The two permissions are deliberately NOT bundled.
    //
    // TWO dynamic parameters: `{wallet}` and `{allocation}`. Both are
    // constrained to numeric ids. The controller resolves them in the
    // frozen two-stage order (wallet first, then allocation scoped to
    // that wallet; 2B.29 §"Two-stage tenant scoping").
    //
    // Declared AFTER `allocations.store` for readability; the two paths
    // are structurally distinct (three segments vs. five) and cannot
    // collide.
    // =========================================================================
    Route::post(
        '/{wallet}/allocations/{allocation}/reverse',
        [AllocationController::class, 'reverse'],
    )
        ->middleware('auth.permission:wallet.allocation.reverse')
        ->whereNumber('wallet')
        ->whereNumber('allocation')
        ->name('allocations.reverse');

    // =========================================================================
    // Route #9 — Generate a statement.
    //
    //     GET /wallets/{wallet}/statement
    //
    // NO route middleware. The `viewStatement` ability has an OR across
    // `wallet.statement.view` and `wallet.statement.view_own`; the
    // controller calls `Gate::authorize('viewStatement', $wallet)` and
    // the policy (2B.37) grants via either path. This is the S7-A
    // amendment from Step 7 v1.1.0 §C.1.1.
    //
    // The `from` and `to` period parameters are validated inline by the
    // controller, not by a FormRequest. See 2B.30 §"Period parameters".
    // =========================================================================
    Route::get('/{wallet}/statement', [StatementController::class, 'show'])
        ->whereNumber('wallet')
        ->name('statement.show');
});